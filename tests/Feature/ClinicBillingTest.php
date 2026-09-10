<?php

namespace Tests\Feature;

use App\Enums\RoleKey;
use App\Enums\RoleScope;
use App\Models\Clinic;
use App\Models\ClinicInvoice;
use App\Models\ClinicInvoiceItem;
use App\Models\ClinicInvoicePayment;
use App\Models\ClinicMembership;
use App\Models\ClinicSubscription;
use App\Models\PatientProfile;
use App\Models\Plan;
use App\Models\Role;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class ClinicBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
    }

    public function test_clinic_admin_only_sees_invoices_from_the_request_resolved_clinic_and_current_subscription(): void
    {
        [$clinic, $adminRole, $subscription] = $this->createClinicRole(RoleKey::ClinicAdmin, 'Administrador Clínico');
        $otherClinic = Clinic::factory()->create();
        ClinicSubscription::factory()->create([
            'clinic_id' => $otherClinic->id,
            'plan_id' => $subscription->plan_id,
        ]);
        $admin = User::factory()->create([
            'phone_number' => '+525510000041',
            'password' => 'SecurePass123',
        ]);
        $this->attachMembership($clinic, $admin, $adminRole);

        $patient = User::factory()->create(['name' => 'Paciente de la clínica']);
        $patientProfile = PatientProfile::factory()->create([
            'user_id' => $patient->id,
            'clinic_id' => $clinic->id,
        ]);
        $otherPatient = User::factory()->create(['name' => 'Paciente de otra clínica']);
        $otherPatientProfile = PatientProfile::factory()->create([
            'user_id' => $otherPatient->id,
            'clinic_id' => $otherClinic->id,
        ]);
        $invoice = $this->createInvoice($clinic, $patientProfile, 'CLINIC-001', 1500);
        $otherInvoice = $this->createInvoice($otherClinic, $otherPatientProfile, 'OTHER-001', 2500);

        $this->authenticate($admin, '55 1000 0041', RoleKey::ClinicAdmin);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/clinic/billing/invoices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $invoice->id)
            ->assertJsonPath('data.0.invoice_number', 'CLINIC-001')
            ->assertJsonPath('meta.total', 1);

        $this->withSpaOrigin()
            ->getJson("/api/v1/auth/clinic/billing/invoices/{$otherInvoice->id}")
            ->assertNotFound();

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/clinic/billing/subscription')
            ->assertOk()
            ->assertJsonPath('data.subscription.id', $subscription->id)
            ->assertJsonPath('data.subscription.plan.key', 'basic');
    }

    public function test_clinic_billing_requires_authentication(): void
    {
        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/clinic/billing/invoices')
            ->assertUnauthorized();
    }

    public function test_receptionist_can_read_patient_billing_and_record_payment_but_cannot_manage_invoices_or_subscription(): void
    {
        [$clinic, $receptionistRole] = $this->createClinicRole(RoleKey::Receptionist, 'Recepcionista');
        $receptionist = User::factory()->create([
            'phone_number' => '+525510000042',
            'password' => 'SecurePass123',
        ]);
        $this->attachMembership($clinic, $receptionist, $receptionistRole);
        $patient = User::factory()->create(['name' => 'Paciente de recepción']);
        $patientProfile = PatientProfile::factory()->create([
            'user_id' => $patient->id,
            'clinic_id' => $clinic->id,
        ]);
        $invoice = $this->createInvoice($clinic, $patientProfile, 'RECEPTION-001', 3000);
        $this->authenticate($receptionist, '55 1000 0042', RoleKey::Receptionist);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/clinic/billing/invoices')
            ->assertOk()
            ->assertJsonPath('data.0.id', $invoice->id);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/clinic/billing/report?from='.now()->startOfMonth()->toDateString().'&to='.now()->endOfMonth()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.by_currency.USD.invoice_count', 1);

        $this->withSpaOrigin()
            ->postJson("/api/v1/auth/clinic/billing/invoices/{$invoice->id}/payments", [
                'amount_minor' => 3000,
                'currency' => 'USD',
                'payment_method' => 'cash',
            ])
            ->assertCreated()
            ->assertJsonPath('data.invoice.status', 'paid');

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/clinic/billing/invoices', [])
            ->assertForbidden();

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/clinic/billing/subscription')
            ->assertForbidden();
    }

    public function test_payments_transition_an_invoice_from_issued_to_partial_and_full_payment(): void
    {
        [$clinic, $adminRole] = $this->createClinicRole(RoleKey::ClinicAdmin, 'Administrador Clínico');
        $admin = User::factory()->create([
            'phone_number' => '+525510000043',
            'password' => 'SecurePass123',
        ]);
        $this->attachMembership($clinic, $admin, $adminRole);
        $patient = User::factory()->create(['name' => 'Paciente facturado']);
        $patientProfile = PatientProfile::factory()->create([
            'user_id' => $patient->id,
            'clinic_id' => $clinic->id,
        ]);
        $this->authenticate($admin, '55 1000 0043', RoleKey::ClinicAdmin);

        $invoiceResponse = $this->withSpaOrigin()
            ->postJson('/api/v1/auth/clinic/billing/invoices', [
                'patient_id' => $patientProfile->id,
                'invoice_number' => 'PARTIAL-001',
                'issue_date' => now()->toDateString(),
                'currency' => 'USD',
                'items' => [
                    [
                        'description' => 'Consulta inicial',
                        'quantity' => 1,
                        'unit_amount_minor' => 3000,
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.total_minor', 3000)
            ->assertJsonPath('data.status', 'issued');
        $invoiceId = $invoiceResponse->json('data.id');

        $this->withSpaOrigin()
            ->postJson("/api/v1/auth/clinic/billing/invoices/{$invoiceId}/payments", [
                'amount_minor' => 1000,
                'currency' => 'USD',
                'payment_method' => 'bank_transfer',
                'reference' => 'PARTIAL-PAYMENT-001',
                'paid_at' => now()->toIso8601String(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.payment.status', 'confirmed')
            ->assertJsonPath('data.invoice.status', 'partially_paid')
            ->assertJsonPath('data.invoice.paid_minor', 1000)
            ->assertJsonPath('data.invoice.outstanding_minor', 2000);

        $this->withSpaOrigin()
            ->postJson("/api/v1/auth/clinic/billing/invoices/{$invoiceId}/payments", [
                'amount_minor' => 2000,
                'currency' => 'USD',
                'payment_method' => 'cash',
                'reference' => 'PARTIAL-PAYMENT-002',
                'paid_at' => now()->toIso8601String(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.invoice.status', 'paid')
            ->assertJsonPath('data.invoice.paid_minor', 3000)
            ->assertJsonPath('data.invoice.outstanding_minor', 0);

        $this->assertDatabaseHas('clinic_invoices', [
            'id' => $invoiceId,
            'status' => 'paid',
            'total_minor' => 3000,
        ]);
        $this->assertDatabaseCount('clinic_invoice_payments', 2);
    }

    public function test_period_report_aggregates_patient_billing_by_currency_without_platform_subscription_revenue(): void
    {
        [$clinic, $adminRole, $subscription] = $this->createClinicRole(RoleKey::ClinicAdmin, 'Administrador Clínico');
        $admin = User::factory()->create([
            'phone_number' => '+525510000044',
            'password' => 'SecurePass123',
        ]);
        $this->attachMembership($clinic, $admin, $adminRole);
        $patient = User::factory()->create();
        $patientProfile = PatientProfile::factory()->create([
            'user_id' => $patient->id,
            'clinic_id' => $clinic->id,
        ]);
        $from = now()->startOfMonth();
        $to = $from->copy()->endOfMonth();
        $usdInvoice = $this->createInvoice($clinic, $patientProfile, 'REPORT-USD-001', 10000, 'USD', 'partially_paid', $from->copy()->addDays(2));
        $mxnInvoice = $this->createInvoice($clinic, $patientProfile, 'REPORT-MXN-001', 20000, 'MXN', 'partially_paid', $from->copy()->addDays(3));
        ClinicInvoicePayment::factory()->create([
            'clinic_invoice_id' => $usdInvoice->id,
            'amount_minor' => 2500,
            'currency' => 'USD',
            'paid_at' => $from->copy()->addDays(4),
        ]);
        ClinicInvoicePayment::factory()->create([
            'clinic_invoice_id' => $mxnInvoice->id,
            'amount_minor' => 5000,
            'currency' => 'MXN',
            'paid_at' => $from->copy()->addDays(5),
        ]);
        SubscriptionPayment::factory()->create([
            'clinic_subscription_id' => $subscription->id,
            'amount_cents' => 999999,
            'currency' => 'USD',
            'paid_at' => $from->copy()->addDays(6),
        ]);
        $this->authenticate($admin, '55 1000 0044', RoleKey::ClinicAdmin);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/clinic/billing/report?from='.$from->toDateString().'&to='.$to->toDateString())
            ->assertOk()
            ->assertJsonPath('data.by_currency.USD.invoice_count', 1)
            ->assertJsonPath('data.by_currency.USD.invoiced_minor', 10000)
            ->assertJsonPath('data.by_currency.USD.paid_minor', 2500)
            ->assertJsonPath('data.by_currency.USD.outstanding_minor', 7500)
            ->assertJsonPath('data.by_currency.MXN.invoice_count', 1)
            ->assertJsonPath('data.by_currency.MXN.invoiced_minor', 20000)
            ->assertJsonPath('data.by_currency.MXN.paid_minor', 5000)
            ->assertJsonPath('data.by_currency.MXN.outstanding_minor', 15000)
            ->assertJsonPath('data.by_currency.PEN.paid_minor', 0);
    }

    /** @return array{0: Clinic, 1: Role, 2: ClinicSubscription} */
    private function createClinicRole(RoleKey $roleKey, string $roleName): array
    {
        $clinic = Clinic::factory()->create();
        $plan = Plan::factory()->create();
        $subscription = ClinicSubscription::factory()->create([
            'clinic_id' => $clinic->id,
            'plan_id' => $plan->id,
        ]);
        $role = Role::query()->create([
            'key' => $roleKey->value,
            'name' => $roleName,
            'scope' => RoleScope::Organization,
            'is_system' => true,
        ]);

        return [$clinic, $role, $subscription];
    }

    private function attachMembership(Clinic $clinic, User $user, Role $role): void
    {
        $user->roles()->sync([$role->id]);
        ClinicMembership::query()->create([
            'clinic_id' => $clinic->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
    }

    private function createInvoice(
        Clinic $clinic,
        PatientProfile $patient,
        string $invoiceNumber,
        int $totalMinor,
        string $currency = 'USD',
        string $status = 'issued',
        mixed $issueDate = null,
    ): ClinicInvoice {
        $invoice = ClinicInvoice::query()->create([
            'clinic_id' => $clinic->id,
            'patient_profile_id' => $patient->id,
            'invoice_number' => $invoiceNumber,
            'issue_date' => $issueDate ?? now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'total_minor' => $totalMinor,
            'currency' => $currency,
            'status' => $status,
        ]);
        ClinicInvoiceItem::query()->create([
            'clinic_invoice_id' => $invoice->id,
            'description' => 'Servicio médico',
            'quantity' => 1,
            'unit_amount_minor' => $totalMinor,
            'line_total_minor' => $totalMinor,
        ]);

        return $invoice;
    }

    private function authenticate(User $user, string $phone, RoleKey $roleKey): void
    {
        $login = $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'country_code' => 'MX',
            'phone_number' => $phone,
            'password' => 'SecurePass123',
            'requested_role' => $roleKey->value,
        ])->assertAccepted();
        $verification = $this->withSpaOrigin()->postJson('/api/v1/auth/login/verify-otp', [
            'challenge_id' => $login->json('data.challenge_id'),
            'code' => '123456',
        ])->assertOk();
        $sessionCookie = $verification->getCookie(config('session.cookie'));
        $this->withCookie($sessionCookie->getName(), $sessionCookie->getValue());
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }
}
