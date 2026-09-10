<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Admin\RecordAdminAuditEvent;
use App\Enums\ClinicStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AdminBillingIndexRequest;
use App\Http\Requests\Api\V1\AdminManualPaymentRefundRequest;
use App\Http\Requests\Api\V1\AdminManualPaymentStoreRequest;
use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\PatientProfile;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Support\AdminDashboardCache;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminBillingController extends Controller
{
    public function index(AdminBillingIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        unset($filters['format']);

        $payload = AdminDashboardCache::remember(
            AdminDashboardCache::resourceKey('billing', $request->user()?->getKey(), $filters),
            fn (): array => $this->indexPayload($filters),
        );

        return response()->json($payload);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function indexPayload(array $filters): array
    {
        $payments = $this->filteredPayments($filters)
            ->latest('id')
            ->paginate($filters['per_page'] ?? 15);

        return [
            'success' => true,
            'data' => $payments->getCollection()->map(fn (SubscriptionPayment $payment): array => $this->serializePayment($payment))->values()->all(),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'from' => $payments->firstItem(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'to' => $payments->lastItem(),
                'total' => $payments->total(),
            ],
            'summary' => $this->summary(),
            'filter_options' => [
                'countries' => Clinic::query()->distinct()->orderBy('country_code')->pluck('country_code')->values(),
                'statuses' => SubscriptionPayment::query()->distinct()->orderBy('status')->pluck('status')->values(),
                'manual_subscriptions' => ClinicSubscription::query()
                    ->with(['clinic:id,name,country_code', 'plan:id,name'])
                    ->where('provider', 'admin_manual')
                    ->where('status', 'active')
                    ->latest('id')
                    ->get()
                    ->map(fn (ClinicSubscription $subscription): array => [
                        'id' => $subscription->id,
                        'clinic' => $subscription->clinic?->name,
                        'country_code' => $subscription->clinic?->country_code,
                        'plan' => $subscription->plan?->name,
                    ])->values()->all(),
            ],
        ];
    }

    public function storeManualPayment(
        AdminManualPaymentStoreRequest $request,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        $data = $request->validated();
        $subscription = ClinicSubscription::query()
            ->with(['clinic', 'plan'])
            ->findOrFail($data['clinic_subscription_id']);

        if (
            $subscription->provider !== 'admin_manual'
            || $subscription->status->value !== 'active'
            || $subscription->clinic?->status?->value !== ClinicStatus::Active->value
            || ! $subscription->plan?->is_active
        ) {
            throw ValidationException::withMessages([
                'clinic_subscription_id' => ['Solo se pueden conciliar pagos de suscripciones manuales activas de clínicas y planes activos.'],
            ]);
        }

        $payment = DB::transaction(function () use ($data): SubscriptionPayment {
            return SubscriptionPayment::query()->create([
                'clinic_subscription_id' => $data['clinic_subscription_id'],
                'idempotency_key' => 'admin_manual:'.hash('sha256', $data['reference']),
                'amount_cents' => (int) round(((float) $data['amount']) * 100),
                'currency' => strtoupper($data['currency']),
                'status' => 'succeeded',
                'provider' => 'admin_manual',
                'provider_reference' => trim($data['reference']),
                'reconciliation_note' => $data['note'] ?? null,
                'paid_at' => $data['paid_at'],
            ]);
        });
        $payment->load(['subscription.clinic', 'subscription.plan']);

        $audit->handle(
            $request->user(),
            $request,
            'billing',
            'manual_payment_recorded',
            SubscriptionPayment::class,
            $payment->id,
            $payment->provider_reference,
            $subscription->clinic,
            metadata: [
                'amount_cents' => $payment->amount_cents,
                'currency' => $payment->currency,
                'subscription_id' => $subscription->id,
            ],
            critical: true,
        );

        return response()->json(['success' => true, 'data' => $this->serializePayment($payment)], 201);
    }

    public function refundManualPayment(
        SubscriptionPayment $payment,
        AdminManualPaymentRefundRequest $request,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        if ($payment->provider !== 'admin_manual' || $payment->status !== 'succeeded') {
            throw ValidationException::withMessages([
                'payment' => ['Solo se pueden reembolsar pagos manuales confirmados.'],
            ]);
        }

        $data = $request->validated();
        $payment->update([
            'status' => 'refunded',
            'refund_reference' => trim($data['reference']),
            'reconciliation_note' => trim(implode("\n\n", array_filter([
                $payment->reconciliation_note,
                'Motivo del reembolso: '.trim($data['reason']),
            ]))),
            'refunded_at' => now(),
        ]);
        $payment->load(['subscription.clinic', 'subscription.plan']);

        $audit->handle(
            $request->user(),
            $request,
            'billing',
            'manual_payment_refunded',
            SubscriptionPayment::class,
            $payment->id,
            $payment->provider_reference,
            $payment->subscription?->clinic,
            metadata: [
                'amount_cents' => $payment->amount_cents,
                'currency' => $payment->currency,
                'refund_reference' => $payment->refund_reference,
                'reason' => $data['reason'],
            ],
            critical: true,
        );

        return response()->json(['success' => true, 'data' => $this->serializePayment($payment)]);
    }

    public function export(
        AdminBillingIndexRequest $request,
        RecordAdminAuditEvent $audit,
    ): StreamedResponse {
        $filters = $request->validated();
        $format = $filters['format'] ?? 'csv';
        unset($filters['format']);

        $audit->handle(
            $request->user(),
            $request,
            'billing',
            'billing_exported',
            metadata: ['filters' => $filters, 'format' => $format],
        );

        if ($format === 'xlsx') {
            return response()->streamDownload(function () use ($filters): void {
                $spreadsheet = $this->billingSpreadsheet($filters);
                (new Xlsx($spreadsheet))->save('php://output');
                $spreadsheet->disconnectWorksheets();
            }, 'facturacion-'.now()->format('Y-m-d-His').'.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        }

        return response()->streamDownload(function () use ($filters): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['ID', 'Referencia', 'Clínica', 'País', 'Plan', 'Estado', 'Monto', 'Moneda', 'Proveedor', 'Fecha de pago']);
            $this->filteredPayments($filters)->latest('id')->chunkById(200, function ($payments) use ($output): void {
                foreach ($payments as $payment) {
                    $item = $this->serializePayment($payment);
                    fputcsv($output, [$item['id'], $item['reference'], $item['clinic']['name'], $item['clinic']['country_code'], $item['plan'], $item['status'], $item['amount_cents'] / 100, $item['currency'], $item['provider'], $item['paid_at']]);
                }
            });
            fclose($output);
        }, 'facturacion-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function reportExport(RecordAdminAuditEvent $audit, AdminBillingIndexRequest $request): StreamedResponse
    {
        $filters = $request->validated();
        $format = $filters['format'] ?? 'xlsx';
        $audit->handle($request->user(), $request, 'reports', 'executive_report_exported', metadata: ['format' => $format]);
        $summary = $this->summary();
        $countries = $summary['countries'];
        $generatedAt = CarbonImmutable::now();

        if ($format === 'xlsx') {
            return response()->streamDownload(function () use ($summary, $countries, $generatedAt): void {
                $spreadsheet = $this->reportSpreadsheet($summary, $countries, $generatedAt);
                $writer = new Xlsx($spreadsheet);
                $writer->setPreCalculateFormulas(false);
                $writer->save('php://output');
                $spreadsheet->disconnectWorksheets();
            }, 'reporte-ejecutivo-'.$generatedAt->format('Y-m-d-His').'.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        }

        return response()->streamDownload(function () use ($summary, $countries): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Reporte ejecutivo global', now()->toIso8601String()]);
            fputcsv($output, []);
            fputcsv($output, ['Indicador', 'Valor']);
            fputcsv($output, ['Clínicas activas', Clinic::query()->where('status', ClinicStatus::Active->value)->count()]);
            fputcsv($output, ['Usuarios activos', User::query()->where('status', UserStatus::Active->value)->count()]);
            fputcsv($output, ['Pacientes activos', PatientProfile::query()->whereHas('user', fn ($query) => $query->where('status', UserStatus::Active->value))->count()]);
            foreach ($this->supportedCurrencies() as $currency) {
                fputcsv($output, ["Ingresos del mes {$currency}", $summary['by_currency'][$currency]['monthly_revenue_cents'] / 100]);
            }
            fputcsv($output, []);
            fputcsv($output, ['País', 'Clínicas activas', 'Usuarios activos', ...array_map(fn (string $currency): string => "Ingresos acumulados {$currency}", $this->supportedCurrencies())]);
            foreach ($countries as $country) {
                fputcsv($output, [
                    $country['country_code'],
                    $country['clinics'],
                    $country['users'],
                    ...array_map(fn (string $currency): float => $country['revenue_by_currency'][$currency] / 100, $this->supportedCurrencies()),
                ]);
            }
            fclose($output);
        }, 'reporte-ejecutivo-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @param array<string, mixed> $filters */
    private function filteredPayments(array $filters): Builder
    {
        return SubscriptionPayment::query()
            ->with(['subscription.clinic:id,name,country_code', 'subscription.plan:id,name'])
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('provider_reference', 'like', "%{$search}%")
                        ->orWhere('idempotency_key', 'like', "%{$search}%")
                        ->orWhereHas('subscription.clinic', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($filters['country_code'] ?? null, fn (Builder $query, string $country) => $query->whereHas('subscription.clinic', fn (Builder $query) => $query->where('country_code', $country)))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('paid_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('paid_at', '<=', $date));
    }

    /** @return array<string, mixed> */
    private function serializePayment(SubscriptionPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'reference' => $payment->provider_reference,
            'clinic' => [
                'id' => $payment->subscription?->clinic?->id,
                'name' => $payment->subscription?->clinic?->name ?? 'Clínica eliminada',
                'country_code' => $payment->subscription?->clinic?->country_code,
            ],
            'plan' => $payment->subscription?->plan?->name,
            'status' => $payment->status,
            'amount_cents' => $payment->amount_cents,
            'currency' => $payment->currency,
            'provider' => $payment->provider,
            'reconciliation_note' => $payment->reconciliation_note,
            'refund_reference' => $payment->refund_reference,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'refunded_at' => $payment->refunded_at?->toIso8601String(),
            'created_at' => $payment->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function summary(): array
    {
        $now = CarbonImmutable::now();
        $month = $now->startOfMonth();
        $previousMonth = $month->subMonth();
        $succeeded = SubscriptionPayment::query()->where('status', 'succeeded')->whereNotNull('paid_at');
        $currencyTotals = (clone $succeeded)
            ->select('currency')
            ->selectRaw('SUM(amount_cents) as amount_cents')
            ->groupBy('currency')
            ->pluck('amount_cents', 'currency')
            ->map(fn ($amount): int => (int) $amount);
        $monthlyTotals = $this->monthlyTotals($succeeded, $previousMonth);
        $byCurrency = [];

        foreach ($this->supportedCurrencies() as $currency) {
            $revenue = $this->revenueSeries($now, $currency, $monthlyTotals);
            $byCurrency[$currency] = [
                'monthly_revenue_cents' => $this->amountForMonth($revenue, $month),
                'previous_month_revenue_cents' => $this->amountForMonth($revenue, $previousMonth),
                'total_revenue_cents' => $currencyTotals->get($currency, 0),
                'revenue' => $revenue,
            ];
        }

        return [
            'monthly_revenue_cents' => $byCurrency['USD']['monthly_revenue_cents'],
            'previous_month_revenue_cents' => $byCurrency['USD']['previous_month_revenue_cents'],
            'successful_payments' => SubscriptionPayment::query()->where('status', 'succeeded')->count(),
            'pending_payments' => SubscriptionPayment::query()->where('status', 'pending')->count(),
            'failed_payments' => SubscriptionPayment::query()->where('status', 'failed')->count(),
            'total_revenue_cents' => $byCurrency['USD']['total_revenue_cents'],
            'revenue' => $byCurrency['USD']['revenue'],
            'countries' => $this->countrySummary(),
            'by_currency' => $byCurrency,
        ];
    }

    /**
     * @return Collection<string, Collection<string, int>>
     */
    private function monthlyTotals(Builder $succeeded, CarbonImmutable $start): Collection
    {
        $monthExpression = $this->monthExpression();
        $rows = (clone $succeeded)
            ->where('paid_at', '>=', $start)
            ->select('currency')
            ->selectRaw("{$monthExpression} as month")
            ->selectRaw('SUM(amount_cents) as amount_cents')
            ->groupBy('currency')
            ->groupByRaw($monthExpression)
            ->get();

        return $rows->groupBy('currency')->map(
            fn (Collection $currencyRows): Collection => $currencyRows->mapWithKeys(
                fn ($row): array => [(string) $row->month => (int) $row->amount_cents],
            ),
        );
    }

    /** @param Collection<string, Collection<string, int>> $monthlyTotals */
    private function revenueSeries(CarbonImmutable $now, string $currency, Collection $monthlyTotals): array
    {
        $start = $now->startOfMonth()->subMonths(11);
        $totals = $monthlyTotals->get($currency, collect());

        return collect(range(0, 11))->map(function (int $offset) use ($start, $totals): array {
            $month = $start->addMonths($offset);

            return ['month' => $month->format('Y-m'), 'amount_cents' => (int) $totals->get($month->format('Y-m'), 0)];
        })->all();
    }

    /** @param list<array{month: string, amount_cents: int}> $revenue */
    private function amountForMonth(array $revenue, CarbonImmutable $month): int
    {
        return (int) collect($revenue)->firstWhere('month', $month->format('Y-m'))['amount_cents'];
    }

    /** @return list<array<string, mixed>> */
    private function countrySummary(): array
    {
        $countries = Clinic::query()->distinct()->orderBy('country_code')->pluck('country_code');
        $clinicCounts = Clinic::query()
            ->where('status', ClinicStatus::Active->value)
            ->select('country_code')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('country_code')
            ->pluck('count', 'country_code');
        $userCounts = User::query()
            ->where('status', UserStatus::Active->value)
            ->select('country_code')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('country_code')
            ->pluck('count', 'country_code');
        $revenueRows = SubscriptionPayment::query()
            ->join('clinic_subscriptions', 'clinic_subscriptions.id', '=', 'subscription_payments.clinic_subscription_id')
            ->join('clinics', 'clinics.id', '=', 'clinic_subscriptions.clinic_id')
            ->where('subscription_payments.status', 'succeeded')
            ->whereNotNull('subscription_payments.paid_at')
            ->select('clinics.country_code', 'subscription_payments.currency')
            ->selectRaw('SUM(subscription_payments.amount_cents) as amount_cents')
            ->groupBy('clinics.country_code', 'subscription_payments.currency')
            ->get()
            ->groupBy('country_code');

        return $countries->map(function (string $country) use ($clinicCounts, $userCounts, $revenueRows): array {
            $revenueByCurrency = array_fill_keys($this->supportedCurrencies(), 0);

            foreach ($revenueRows->get($country, collect()) as $row) {
                if (array_key_exists($row->currency, $revenueByCurrency)) {
                    $revenueByCurrency[$row->currency] = (int) $row->amount_cents;
                }
            }

            return [
                'country_code' => $country,
                'clinics' => (int) ($clinicCounts[$country] ?? 0),
                'users' => (int) ($userCounts[$country] ?? 0),
                'revenue_cents' => $revenueByCurrency['USD'],
                'revenue_by_currency' => $revenueByCurrency,
            ];
        })->values()->all();
    }

    /** @param array<string, mixed> $filters */
    private function billingSpreadsheet(array $filters): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Facturación');
        $headers = ['ID', 'Referencia', 'Clínica', 'País', 'Plan', 'Estado', 'Monto', 'Moneda', 'Proveedor', 'Fecha de pago'];
        $sheet->fromArray($headers, null, 'A1');
        $rowNumber = 2;

        $this->filteredPayments($filters)->latest('id')->chunkById(200, function ($payments) use ($sheet, &$rowNumber): void {
            foreach ($payments as $payment) {
                $item = $this->serializePayment($payment);
                $sheet->fromArray([
                    $item['id'],
                    $item['reference'],
                    $item['clinic']['name'],
                    $item['clinic']['country_code'],
                    $item['plan'],
                    $item['status'],
                    $item['amount_cents'] / 100,
                    $item['currency'],
                    $item['provider'],
                    $item['paid_at'],
                ], null, "A{$rowNumber}");
                $rowNumber++;
            }
        });

        $this->formatWorksheet($sheet, 'A1:J'.max(1, $rowNumber - 1), 'J', 'G');

        return $spreadsheet;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  list<array<string, mixed>>  $countries
     */
    private function reportSpreadsheet(array $summary, array $countries, CarbonImmutable $generatedAt): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setCreator((string) config('app.name', 'Hipocratiq'))
            ->setTitle('Reporte ejecutivo global')
            ->setSubject('Resumen ejecutivo global')
            ->setDescription('Indicadores operativos, cobertura e ingresos reales de la plataforma.');
        $summarySheet = $spreadsheet->getActiveSheet();
        $summarySheet->setTitle('Resumen ejecutivo');
        $summarySheet->setShowGridlines(false);
        $summarySheet->mergeCells('A1:D1');
        $summarySheet->setCellValue('A1', 'Reporte ejecutivo global');
        $summarySheet->mergeCells('A2:D2');
        $summarySheet->setCellValue('A2', 'Resumen global basado en operación, pagos y auditoría reales');
        $summarySheet->setCellValue('A3', 'Generado el');
        $summarySheet->setCellValue('B3', ExcelDate::PHPToExcel($generatedAt));
        $summarySheet->getStyle('B3')->getNumberFormat()->setFormatCode('dd/mm/yyyy hh:mm');
        $summarySheet->mergeCells('C3:D3');
        $summarySheet->setCellValue('C3', 'Datos reales al momento de la consulta');
        $summarySheet->mergeCells('A5:D5');
        $summarySheet->setCellValue('A5', 'Indicadores ejecutivos');
        $summaryRows = [
            ['Indicador', 'Valor', 'Unidad', 'Período'],
            ['Clínicas activas', Clinic::query()->where('status', ClinicStatus::Active->value)->count(), 'clínicas', 'Actual'],
            ['Usuarios activos', User::query()->where('status', UserStatus::Active->value)->count(), 'usuarios', 'Actual'],
            ['Pacientes activos', PatientProfile::query()->whereHas('user', fn ($query) => $query->where('status', UserStatus::Active->value))->count(), 'pacientes', 'Actual'],
        ];
        foreach ($this->supportedCurrencies() as $currency) {
            $summaryRows[] = ["Ingresos del mes {$currency}", $summary['by_currency'][$currency]['monthly_revenue_cents'] / 100, $currency, 'Mes actual'];
        }
        $summarySheet->fromArray($summaryRows, null, 'A6');
        $summaryLastRow = 5 + count($summaryRows);
        $this->styleReportTitle($summarySheet, 'A1:D1', 'A2:D3', 'A5:D5', 'A6:D6');
        $summarySheet->getStyle("A7:D{$summaryLastRow}")->applyFromArray($this->reportBodyStyle());
        $summarySheet->getStyle('B7:B9')->getNumberFormat()->setFormatCode('#,##0');
        foreach ($this->supportedCurrencies() as $offset => $currency) {
            $summaryRow = 10 + $offset;
            $summarySheet->getStyle("B{$summaryRow}")->getNumberFormat()->setFormatCode($this->currencyFormatCode($currency));
        }
        $summarySheet->getStyle('B7:B'.$summaryLastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $summarySheet->getColumnDimension('A')->setWidth(32);
        $summarySheet->getColumnDimension('B')->setWidth(18);
        $summarySheet->getColumnDimension('C')->setWidth(18);
        $summarySheet->getColumnDimension('D')->setWidth(20);
        $summarySheet->getRowDimension(1)->setRowHeight(30);
        $summarySheet->getRowDimension(2)->setRowHeight(20);
        $summarySheet->getRowDimension(3)->setRowHeight(20);
        $summarySheet->getRowDimension(5)->setRowHeight(23);
        $summarySheet->getRowDimension(6)->setRowHeight(28);
        $summarySheet->setAutoFilter("A6:D{$summaryLastRow}");
        $summarySheet->freezePane('A7');

        $countrySheet = $spreadsheet->createSheet();
        $countrySheet->setTitle('Países');
        $countrySheet->setShowGridlines(false);
        $currencies = $this->supportedCurrencies();
        $lastCountryColumn = Coordinate::stringFromColumnIndex(3 + count($currencies));
        $countrySheet->mergeCells("A1:{$lastCountryColumn}1");
        $countrySheet->setCellValue('A1', 'Cobertura por país');
        $countrySheet->mergeCells("A2:{$lastCountryColumn}2");
        $countrySheet->setCellValue('A2', 'Clínicas, usuarios e ingresos acumulados desde datos reales');
        $countrySheet->setCellValue('A3', 'Generado el');
        $countrySheet->setCellValue('B3', ExcelDate::PHPToExcel($generatedAt));
        $countrySheet->getStyle('B3')->getNumberFormat()->setFormatCode('dd/mm/yyyy hh:mm');
        $countrySheet->mergeCells("C3:{$lastCountryColumn}3");
        $countrySheet->setCellValue('C3', 'Usa los filtros para explorar los datos por país o moneda');
        $countrySheet->setCellValue('A5', 'País');
        $countrySheet->mergeCells('B5:C5');
        $countrySheet->setCellValue('B5', 'Actividad');
        $countrySheet->mergeCells("D5:{$lastCountryColumn}5");
        $countrySheet->setCellValue('D5', 'Ingresos acumulados');
        $countryHeaders = ['País', 'Clínicas activas', 'Usuarios activos', ...array_map(static fn (string $currency): string => "Ingresos {$currency}", $currencies)];
        $countrySheet->fromArray([$countryHeaders], null, 'A6');
        $rowNumber = 7;
        foreach ($countries as $country) {
            $countrySheet->fromArray([
                $country['country_code'],
                $country['clinics'],
                $country['users'],
                ...array_map(static fn (string $currency): float => ((int) ($country['revenue_by_currency'][$currency] ?? 0)) / 100, $currencies),
            ], null, "A{$rowNumber}");
            $rowNumber++;
        }
        $countryLastRow = max(6, $rowNumber - 1);
        $this->styleReportTitle($countrySheet, "A1:{$lastCountryColumn}1", "A2:{$lastCountryColumn}3", "A5:{$lastCountryColumn}5", "A6:{$lastCountryColumn}6");
        if ($rowNumber > 7) {
            $countrySheet->getStyle("A7:{$lastCountryColumn}{$countryLastRow}")->applyFromArray($this->reportBodyStyle());
            $countrySheet->getStyle("B7:C{$countryLastRow}")->getNumberFormat()->setFormatCode('#,##0');
            foreach ($currencies as $offset => $currency) {
                $currencyColumn = Coordinate::stringFromColumnIndex(4 + $offset);
                $countrySheet->getStyle("{$currencyColumn}7:{$currencyColumn}{$countryLastRow}")
                    ->getNumberFormat()
                    ->setFormatCode($this->currencyFormatCode($currency));
            }
            $countrySheet->getStyle("B7:{$lastCountryColumn}{$countryLastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        $countrySheet->getColumnDimension('A')->setWidth(18);
        $countrySheet->getColumnDimension('B')->setWidth(18);
        $countrySheet->getColumnDimension('C')->setWidth(18);
        foreach (range(4, 3 + count($currencies)) as $columnIndex) {
            $countrySheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setWidth(18);
        }
        $countrySheet->getRowDimension(1)->setRowHeight(30);
        $countrySheet->getRowDimension(2)->setRowHeight(20);
        $countrySheet->getRowDimension(3)->setRowHeight(20);
        $countrySheet->getRowDimension(5)->setRowHeight(23);
        $countrySheet->getRowDimension(6)->setRowHeight(28);
        $countrySheet->setAutoFilter("A6:{$lastCountryColumn}{$countryLastRow}");
        $countrySheet->freezePane('A7');

        return $spreadsheet;
    }

    private function styleReportTitle(Worksheet $sheet, string $titleRange, string $subtitleRange, string $sectionRange, string $headerRange): void
    {
        $sheet->getStyle($titleRange)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 18],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0B1F3A']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle($subtitleRange)->applyFromArray([
            'font' => ['color' => ['argb' => 'FF5A6B85'], 'italic' => true, 'size' => 10],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle($sectionRange)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0F6FFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FF0B1F3A']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEAF2FF']],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF66A3FF']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
    }

    /** @return array<string, mixed> */
    private function reportBodyStyle(): array
    {
        return [
            'borders' => [
                'bottom' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FFD7E2F0'],
                ],
            ],
        ];
    }

    private function currencyFormatCode(string $currency): string
    {
        return '"'.$currency.' "#,##0.00;[Red]("'.$currency.' "#,##0.00)';
    }

    private function formatWorksheet(Worksheet $sheet, string $range, string $lastColumn, string $amountColumns, int $headerRow = 1): void
    {
        $headerRange = "A{$headerRow}:{$lastColumn}{$headerRow}";
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF183B56');
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFD9E0E8');
        $amountRange = str_contains($amountColumns, ':')
            ? $amountColumns.$headerRow.':'.$lastColumn.$sheet->getHighestRow()
            : $amountColumns.($headerRow + 1).':'.$amountColumns.$sheet->getHighestRow();
        $sheet->getStyle($amountRange)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->freezePane('A'.($headerRow + 1));
        $sheet->setAutoFilter($range);
        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }

    /** @return list<string> */
    private function supportedCurrencies(): array
    {
        return config('memberships.supported_currencies', ['USD', 'MXN', 'PEN']);
    }

    private function monthExpression(): string
    {
        return match (DB::getDriverName()) {
            'sqlite' => "strftime('%Y-%m', paid_at)",
            'mysql', 'mariadb' => "DATE_FORMAT(paid_at, '%Y-%m')",
            default => "to_char(paid_at, 'YYYY-MM')",
        };
    }
}
