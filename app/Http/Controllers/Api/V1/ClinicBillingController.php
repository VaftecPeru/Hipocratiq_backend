<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClinicInvoicePaymentStatus;
use App\Enums\ClinicInvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ClinicBillingReportRequest;
use App\Http\Requests\Api\V1\ClinicInvoiceIndexRequest;
use App\Http\Requests\Api\V1\ClinicInvoicePaymentStoreRequest;
use App\Http\Requests\Api\V1\ClinicInvoiceStoreRequest;
use App\Models\Clinic;
use App\Models\ClinicInvoice;
use App\Models\ClinicInvoicePayment;
use App\Models\ClinicSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClinicBillingController extends Controller
{
    public function index(ClinicInvoiceIndexRequest $request): JsonResponse
    {
        $clinic = $this->clinic($request);
        $filters = $request->validated();
        $invoices = $clinic->invoices()
            ->with(['patientProfile.user:id,name,email,phone_number'])
            ->withSum([
                'payments as paid_minor' => fn (Builder $query): Builder => $query
                    ->where('status', ClinicInvoicePaymentStatus::Confirmed->value),
            ], 'amount_minor')
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery
                        ->where('invoice_number', 'like', "%{$search}%")
                        ->orWhereHas('patientProfile.user', function (Builder $patientQuery) use ($search): void {
                            $patientQuery
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                                ->orWhere('phone_number', 'like', "%{$search}%");
                        });
                });
            })
            ->when($filters['patient_id'] ?? null, fn (Builder $query, int $patientId): Builder => $query->where('patient_profile_id', $patientId))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($filters['currency'] ?? null, fn (Builder $query, string $currency): Builder => $query->where('currency', $currency))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('issue_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('issue_date', '<=', $date))
            ->latest('issue_date')
            ->latest('id')
            ->paginate($filters['per_page'] ?? 20);

        return response()->json([
            'success' => true,
            'data' => $invoices->getCollection()
                ->map(fn (ClinicInvoice $invoice): array => $this->serializeInvoice($invoice))
                ->values(),
            'meta' => [
                'current_page' => $invoices->currentPage(),
                'from' => $invoices->firstItem(),
                'last_page' => $invoices->lastPage(),
                'per_page' => $invoices->perPage(),
                'to' => $invoices->lastItem(),
                'total' => $invoices->total(),
            ],
        ]);
    }

    public function store(ClinicInvoiceStoreRequest $request): JsonResponse
    {
        $clinic = $this->clinic($request);
        $data = $request->validated();
        $items = array_values($data['items']);
        $totalMinor = $this->invoiceTotal($items);

        $patient = $clinic->patientProfiles()
            ->whereKey($data['patient_id'])
            ->firstOrFail();

        $invoice = DB::transaction(function () use ($clinic, $data, $items, $totalMinor, $patient): ClinicInvoice {
            $invoice = $clinic->invoices()->create([
                'patient_profile_id' => $patient->getKey(),
                'invoice_number' => $data['invoice_number'],
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'] ?? null,
                'total_minor' => $totalMinor,
                'currency' => $data['currency'],
                'status' => ClinicInvoiceStatus::Issued->value,
            ]);

            $invoice->items()->createMany(array_map(
                static fn (array $item): array => [
                    'description' => $item['description'],
                    'quantity' => (int) $item['quantity'],
                    'unit_amount_minor' => (int) $item['unit_amount_minor'],
                    'line_total_minor' => (int) $item['quantity'] * (int) $item['unit_amount_minor'],
                ],
                $items,
            ));

            return $invoice->load([
                'patientProfile.user:id,name,email,phone_number',
                'items',
                'payments.recordedBy:id,name',
            ]);
        });

        return response()->json([
            'success' => true,
            'data' => $this->serializeInvoice($invoice, true),
        ], 201);
    }

    public function show(Request $request, ClinicInvoice $invoice): JsonResponse
    {
        $invoice = $this->clinicInvoice($this->clinic($request), $invoice)->load([
            'patientProfile.user:id,name,email,phone_number',
            'items',
            'payments.recordedBy:id,name',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->serializeInvoice($invoice, true),
        ]);
    }

    public function storePayment(
        ClinicInvoicePaymentStoreRequest $request,
        ClinicInvoice $invoice,
    ): JsonResponse {
        $clinic = $this->clinic($request);
        $data = $request->validated();

        $result = DB::transaction(function () use ($clinic, $invoice, $data, $request): array {
            $lockedInvoice = $clinic->invoices()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensurePayable($lockedInvoice, $data['currency']);

            $confirmedPaidMinor = (int) $lockedInvoice->payments()
                ->where('status', ClinicInvoicePaymentStatus::Confirmed->value)
                ->sum('amount_minor');
            $amountMinor = (int) $data['amount_minor'];
            $remainingMinor = $lockedInvoice->total_minor - $confirmedPaidMinor;

            if ($amountMinor > $remainingMinor) {
                throw ValidationException::withMessages([
                    'amount_minor' => ['El pago excede el saldo pendiente de la factura.'],
                ]);
            }

            $payment = $lockedInvoice->payments()->create([
                'amount_minor' => $amountMinor,
                'currency' => $data['currency'],
                'status' => ClinicInvoicePaymentStatus::Confirmed->value,
                'payment_method' => $data['payment_method'],
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'paid_at' => $data['paid_at'] ?? now(),
                'recorded_by_user_id' => $request->user()?->getKey(),
            ]);

            $lockedInvoice->update([
                'status' => $amountMinor === $remainingMinor
                    ? ClinicInvoiceStatus::Paid->value
                    : ClinicInvoiceStatus::PartiallyPaid->value,
            ]);

            $lockedInvoice->load([
                'patientProfile.user:id,name,email,phone_number',
                'items',
                'payments.recordedBy:id,name',
            ]);
            $payment->load('recordedBy:id,name');

            return [
                'invoice' => $lockedInvoice,
                'payment' => $payment,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'invoice' => $this->serializeInvoice($result['invoice'], true),
                'payment' => $this->serializePayment($result['payment']),
            ],
        ], 201);
    }

    public function report(ClinicBillingReportRequest $request): JsonResponse
    {
        $clinic = $this->clinic($request);
        $data = $request->validated();
        $from = CarbonImmutable::parse($data['from'])->startOfDay();
        $to = CarbonImmutable::parse($data['to'])->endOfDay();
        $currency = $data['currency'] ?? null;

        $confirmedPayments = ClinicInvoicePayment::query()
            ->where('status', ClinicInvoicePaymentStatus::Confirmed->value)
            ->select('clinic_invoice_id')
            ->selectRaw('SUM(amount_minor) AS confirmed_paid_minor')
            ->groupBy('clinic_invoice_id');
        $invoiceRows = ClinicInvoice::query()
            ->leftJoinSub(
                $confirmedPayments,
                'confirmed_payments',
                'confirmed_payments.clinic_invoice_id',
                '=',
                'clinic_invoices.id',
            )
            ->where('clinic_invoices.clinic_id', $clinic->getKey())
            ->whereDate('clinic_invoices.issue_date', '>=', $from->toDateString())
            ->whereDate('clinic_invoices.issue_date', '<=', $to->toDateString())
            ->where('clinic_invoices.status', '!=', ClinicInvoiceStatus::Voided->value)
            ->when($currency, fn (Builder $query, string $value): Builder => $query->where('clinic_invoices.currency', $value))
            ->select('clinic_invoices.currency')
            ->selectRaw('COUNT(clinic_invoices.id) AS invoice_count')
            ->selectRaw('SUM(clinic_invoices.total_minor) AS invoiced_minor')
            ->selectRaw('SUM(clinic_invoices.total_minor - COALESCE(confirmed_payments.confirmed_paid_minor, 0)) AS outstanding_minor')
            ->groupBy('clinic_invoices.currency')
            ->get()
            ->keyBy('currency');

        $paymentRows = ClinicInvoicePayment::query()
            ->join('clinic_invoices', 'clinic_invoices.id', '=', 'clinic_invoice_payments.clinic_invoice_id')
            ->where('clinic_invoices.clinic_id', $clinic->getKey())
            ->where('clinic_invoice_payments.status', ClinicInvoicePaymentStatus::Confirmed->value)
            ->whereBetween('clinic_invoice_payments.paid_at', [$from, $to])
            ->when($currency, fn (Builder $query, string $value): Builder => $query->where('clinic_invoice_payments.currency', $value))
            ->select('clinic_invoice_payments.currency')
            ->selectRaw('COUNT(clinic_invoice_payments.id) AS payment_count')
            ->selectRaw('SUM(clinic_invoice_payments.amount_minor) AS paid_minor')
            ->groupBy('clinic_invoice_payments.currency')
            ->get()
            ->keyBy('currency');

        $currencies = $currency === null
            ? $this->reportCurrencies($invoiceRows->keys()->all(), $paymentRows->keys()->all())
            : [$currency];
        $byCurrency = [];

        foreach ($currencies as $reportCurrency) {
            $invoiceRow = $invoiceRows->get($reportCurrency);
            $paymentRow = $paymentRows->get($reportCurrency);
            $byCurrency[$reportCurrency] = [
                'currency' => $reportCurrency,
                'invoice_count' => (int) ($invoiceRow?->invoice_count ?? 0),
                'invoiced_minor' => (int) ($invoiceRow?->invoiced_minor ?? 0),
                'outstanding_minor' => max(0, (int) ($invoiceRow?->outstanding_minor ?? 0)),
                'payment_count' => (int) ($paymentRow?->payment_count ?? 0),
                'paid_minor' => (int) ($paymentRow?->paid_minor ?? 0),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'clinic_id' => $clinic->getKey(),
                'period' => [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                ],
                'by_currency' => $byCurrency,
            ],
        ]);
    }

    public function exportReport(ClinicBillingReportRequest $request): StreamedResponse
    {
        $clinic = $this->clinic($request);
        $data = $request->validated();
        $from = CarbonImmutable::parse($data['from'])->startOfDay();
        $to = CarbonImmutable::parse($data['to'])->endOfDay();
        $currency = $data['currency'] ?? null;

        $invoices = $clinic->invoices()
            ->with([
                'patientProfile.user:id,name,email,phone_number',
                'payments' => fn ($query) => $query
                    ->where('status', ClinicInvoicePaymentStatus::Confirmed->value)
                    ->orderBy('paid_at'),
            ])
            ->whereDate('issue_date', '>=', $from->toDateString())
            ->whereDate('issue_date', '<=', $to->toDateString())
            ->when($currency, fn (Builder $query, string $value): Builder => $query->where('currency', $value))
            ->latest('issue_date')
            ->latest('id')
            ->get();

        $filename = sprintf(
            'hipocratiq-facturacion-%s-%s.csv',
            $from->format('Ymd'),
            $to->format('Ymd'),
        );

        return response()->streamDownload(function () use ($invoices): void {
            $stream = fopen('php://output', 'wb');

            if ($stream === false) {
                return;
            }

            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, [
                'Factura',
                'Paciente',
                'Correo',
                'Teléfono',
                'Fecha de emisión',
                'Vencimiento',
                'Moneda',
                'Total',
                'Pagado',
                'Pendiente',
                'Estado',
            ]);

            foreach ($invoices as $invoice) {
                $paidMinor = (int) $invoice->payments->sum('amount_minor');
                $totalMinor = (int) $invoice->total_minor;

                fputcsv($stream, [
                    $invoice->invoice_number,
                    $invoice->patientProfile?->user?->name ?? '',
                    $invoice->patientProfile?->user?->email ?? '',
                    $invoice->patientProfile?->user?->phone_number ?? '',
                    $invoice->issue_date?->toDateString() ?? '',
                    $invoice->due_date?->toDateString() ?? '',
                    $invoice->currency,
                    number_format($totalMinor / 100, 2, '.', ''),
                    number_format($paidMinor / 100, 2, '.', ''),
                    number_format(max(0, $totalMinor - $paidMinor) / 100, 2, '.', ''),
                    $invoice->status->value,
                ]);
            }

            fclose($stream);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function subscription(Request $request): JsonResponse
    {
        $subscription = $this->currentSubscription($this->clinic($request));

        return response()->json([
            'success' => true,
            'data' => [
                'subscription' => $subscription === null ? null : $this->serializeSubscription($subscription),
            ],
        ]);
    }

    private function clinic(Request $request): Clinic
    {
        return $request->attributes->get('clinic');
    }

    private function clinicInvoice(Clinic $clinic, ClinicInvoice $invoice): ClinicInvoice
    {
        return $clinic->invoices()->whereKey($invoice->getKey())->firstOrFail();
    }

    private function ensurePayable(ClinicInvoice $invoice, string $currency): void
    {
        if ($invoice->status === ClinicInvoiceStatus::Voided) {
            throw ValidationException::withMessages([
                'invoice' => ['No se puede registrar un pago para una factura anulada.'],
            ]);
        }

        if ($invoice->status === ClinicInvoiceStatus::Paid) {
            throw ValidationException::withMessages([
                'invoice' => ['La factura ya está pagada.'],
            ]);
        }

        if ($invoice->currency !== $currency) {
            throw ValidationException::withMessages([
                'currency' => ['La moneda del pago debe coincidir con la moneda de la factura.'],
            ]);
        }
    }

    /** @param array<int, array<string, mixed>> $items */
    private function invoiceTotal(array $items): int
    {
        $totalMinor = 0;

        foreach ($items as $index => $item) {
            $quantity = (int) $item['quantity'];
            $unitAmountMinor = (int) $item['unit_amount_minor'];

            if ($unitAmountMinor > intdiv(PHP_INT_MAX, $quantity)) {
                throw ValidationException::withMessages([
                    "items.{$index}.unit_amount_minor" => ['El importe de la línea excede el límite permitido.'],
                ]);
            }

            $lineTotalMinor = $quantity * $unitAmountMinor;

            if ($lineTotalMinor > PHP_INT_MAX - $totalMinor) {
                throw ValidationException::withMessages([
                    'items' => ['El total de la factura excede el límite permitido.'],
                ]);
            }

            $totalMinor += $lineTotalMinor;
        }

        if ($totalMinor < 1) {
            throw ValidationException::withMessages([
                'items' => ['La factura debe tener un total mayor que cero.'],
            ]);
        }

        return $totalMinor;
    }

    /** @param array<int, string> ...$groups @return array<int, string> */
    private function reportCurrencies(array ...$groups): array
    {
        $currencies = $this->supportedCurrencies();

        foreach ($groups as $group) {
            foreach ($group as $currency) {
                if (is_string($currency) && ! in_array($currency, $currencies, true)) {
                    $currencies[] = $currency;
                }
            }
        }

        return $currencies;
    }

    /** @return array<int, string> */
    private function supportedCurrencies(): array
    {
        return array_values(array_filter(
            (array) config('memberships.supported_currencies', []),
            static fn (mixed $currency): bool => is_string($currency),
        ));
    }

    private function currentSubscription(Clinic $clinic): ?ClinicSubscription
    {
        return $clinic->subscriptions()
            ->with('plan')
            ->currentlyActive()
            ->latest('starts_at')
            ->first();
    }

    /** @return array<string, mixed> */
    private function serializeInvoice(ClinicInvoice $invoice, bool $includeDetails = false): array
    {
        $paidMinor = $invoice->getAttribute('paid_minor');

        if ($paidMinor === null && $invoice->relationLoaded('payments')) {
            $paidMinor = $invoice->payments
                ->where('status', ClinicInvoicePaymentStatus::Confirmed)
                ->sum('amount_minor');
        }

        $paidMinor = (int) ($paidMinor ?? 0);

        $payload = [
            'id' => $invoice->getKey(),
            'invoice_number' => $invoice->invoice_number,
            'patient' => [
                'id' => $invoice->patientProfile?->getKey(),
                'name' => $invoice->patientProfile?->user?->name,
                'email' => $invoice->patientProfile?->user?->email,
                'phone_number' => $invoice->patientProfile?->user?->phone_number,
            ],
            'issue_date' => $invoice->issue_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'currency' => $invoice->currency,
            'total_minor' => (int) $invoice->total_minor,
            'paid_minor' => $paidMinor,
            'outstanding_minor' => max(0, (int) $invoice->total_minor - $paidMinor),
            'status' => $invoice->status->value,
            'created_at' => $invoice->created_at?->toIso8601String(),
        ];

        if ($includeDetails) {
            $payload['items'] = $invoice->items
                ->map(fn ($item): array => [
                    'id' => $item->getKey(),
                    'description' => $item->description,
                    'quantity' => (int) $item->quantity,
                    'unit_amount_minor' => (int) $item->unit_amount_minor,
                    'line_total_minor' => (int) $item->line_total_minor,
                ])
                ->values()
                ->all();
            $payload['payments'] = $invoice->payments
                ->map(fn (ClinicInvoicePayment $payment): array => $this->serializePayment($payment))
                ->values()
                ->all();
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function serializePayment(ClinicInvoicePayment $payment): array
    {
        return [
            'id' => $payment->getKey(),
            'amount_minor' => (int) $payment->amount_minor,
            'currency' => $payment->currency,
            'status' => $payment->status->value,
            'payment_method' => $payment->payment_method,
            'reference' => $payment->reference,
            'note' => $payment->note,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'recorded_by_user_id' => $payment->recorded_by_user_id,
            'recorded_by_name' => $payment->recordedBy?->name,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeSubscription(ClinicSubscription $subscription): array
    {
        $clinic = $subscription->clinic;

        return [
            'id' => $subscription->getKey(),
            'status' => $subscription->status->value,
            'billing_cycle' => $subscription->billing_cycle->value,
            'auto_renew' => (bool) $subscription->auto_renew,
            'starts_at' => $subscription->starts_at?->toIso8601String(),
            'ends_at' => $subscription->ends_at?->toIso8601String(),
            'provider' => $subscription->provider,
            'plan' => $subscription->plan === null ? null : [
                'id' => $subscription->plan->getKey(),
                'key' => $subscription->plan->key,
                'name' => $subscription->plan->name,
                'description' => $subscription->plan->description,
                'currency' => $subscription->plan->currency,
                'monthly_price_cents' => (int) $subscription->plan->monthly_price_cents,
                'annual_price_cents' => (int) $subscription->plan->annual_price_cents,
                'features' => array_values(array_filter((array) $subscription->plan->features, 'is_string')),
            ],
            'usage' => [
                'patients' => ['used' => $clinic->patientProfiles()->count(), 'limit' => null],
                'users' => ['used' => $clinic->memberships()->where('status', 'active')->count(), 'limit' => null],
                'storage' => ['used' => null, 'limit' => null],
            ],
        ];
    }
}
