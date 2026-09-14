<?php

namespace Tests\Feature;

use App\Models\ClinicSubscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class AdminBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
    }

    public function test_super_admin_receives_real_payments_and_revenue(): void
    {
        $this->authenticateAdmin();
        $payment = SubscriptionPayment::factory()->create(['amount_cents' => 9900, 'paid_at' => now()]);

        $this->withSpaOrigin()->getJson('/api/v1/auth/admin/billing')
            ->assertOk()
            ->assertJsonPath('data.0.id', $payment->id)
            ->assertJsonPath('summary.monthly_revenue_cents', 9900)
            ->assertJsonCount(12, 'summary.revenue');
    }

    public function test_super_admin_can_export_billing_and_executive_report(): void
    {
        $this->authenticateAdmin();
        SubscriptionPayment::factory()->create();

        $this->withSpaOrigin()->get('/api/v1/auth/admin/billing/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $reportResponse = $this->withSpaOrigin()->get('/api/v1/auth/admin/reports/export')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('.xlsx', (string) $reportResponse->headers->get('content-disposition'));
        $this->assertStringStartsWith('PK', $reportResponse->streamedContent());

        $temporaryFile = tempnam(sys_get_temp_dir(), 'hipocratiq-report-');
        $this->assertNotFalse($temporaryFile);
        file_put_contents($temporaryFile, $reportResponse->streamedContent());
        $archive = new \ZipArchive;
        try {
            $this->assertTrue($archive->open($temporaryFile) === true);
            $sheetXml = $archive->getFromName('xl/worksheets/sheet1.xml');
            $sharedStringsXml = $archive->getFromName('xl/sharedStrings.xml');
            $this->assertIsString($sheetXml);
            $this->assertIsString($sharedStringsXml);
            $this->assertStringContainsString('<autoFilter', $sheetXml);
            $this->assertStringContainsString('state="frozen"', $sheetXml);
            $this->assertStringContainsString('Reporte ejecutivo global', $sharedStringsXml);
            $this->assertStringContainsString('Cobertura por país', $sharedStringsXml);
        } finally {
            $archive->close();
            unlink($temporaryFile);
        }
        $this->assertDatabaseHas('admin_audit_events', ['module' => 'billing', 'action' => 'billing_exported']);
        $this->assertDatabaseHas('admin_audit_events', ['module' => 'reports', 'action' => 'executive_report_exported']);
    }

    public function test_super_admin_can_export_billing_as_a_real_xlsx_file(): void
    {
        $this->authenticateAdmin();
        SubscriptionPayment::factory()->create();

        $response = $this->withSpaOrigin()->get('/api/v1/auth/admin/billing/export?format=xlsx')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertStringContainsString('.xlsx', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('PK', $response->streamedContent());

        $temporaryFile = tempnam(sys_get_temp_dir(), 'hipocratiq-billing-');
        $this->assertNotFalse($temporaryFile);
        file_put_contents($temporaryFile, $response->streamedContent());
        $archive = new \ZipArchive;
        try {
            $this->assertTrue($archive->open($temporaryFile) === true);
            $sheetXml = $archive->getFromName('xl/worksheets/sheet1.xml');
            $this->assertIsString($sheetXml);
            $this->assertStringContainsString('<autoFilter', $sheetXml);
        } finally {
            $archive->close();
            unlink($temporaryFile);
        }
    }

    public function test_revenue_summary_keeps_currency_totals_and_months_separate(): void
    {
        $this->authenticateAdmin();
        $subscription = ClinicSubscription::factory()->create();
        SubscriptionPayment::factory()->create(['clinic_subscription_id' => $subscription->id, 'currency' => 'USD', 'amount_cents' => 1000, 'paid_at' => now()]);
        SubscriptionPayment::factory()->create(['clinic_subscription_id' => $subscription->id, 'currency' => 'MXN', 'amount_cents' => 2000, 'paid_at' => now()]);
        SubscriptionPayment::factory()->create(['clinic_subscription_id' => $subscription->id, 'currency' => 'PEN', 'amount_cents' => 3000, 'paid_at' => now()->startOfMonth()->subSecond()]);

        $this->withSpaOrigin()->getJson('/api/v1/auth/admin/billing')
            ->assertOk()
            ->assertJsonPath('summary.by_currency.USD.monthly_revenue_cents', 1000)
            ->assertJsonPath('summary.by_currency.MXN.monthly_revenue_cents', 2000)
            ->assertJsonPath('summary.by_currency.PEN.monthly_revenue_cents', 0)
            ->assertJsonPath('summary.by_currency.PEN.previous_month_revenue_cents', 3000);
    }

    public function test_future_manual_subscriptions_are_excluded_from_billing_filter_options(): void
    {
        $this->authenticateAdmin();
        ClinicSubscription::factory()->create([
            'provider' => 'admin_manual',
            'starts_at' => now()->addDay(),
        ]);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/admin/billing')
            ->assertOk()
            ->assertJsonCount(0, 'filter_options.manual_subscriptions');
    }

    public function test_super_admin_can_record_a_manual_payment_for_a_manual_subscription(): void
    {
        $this->authenticateAdmin();
        $subscription = ClinicSubscription::factory()->create(['provider' => 'admin_manual']);

        $this->withSpaOrigin()->postJson('/api/v1/auth/admin/billing/manual-payments', [
            'clinic_subscription_id' => $subscription->id,
            'amount' => 149.50,
            'currency' => 'USD',
            'reference' => 'BANK-MX-000123',
            'paid_at' => now()->subMinute()->toIso8601String(),
            'note' => 'Transferencia bancaria conciliada.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.amount_cents', 14950);

        $this->assertDatabaseHas('subscription_payments', [
            'clinic_subscription_id' => $subscription->id,
            'provider' => 'admin_manual',
            'provider_reference' => 'BANK-MX-000123',
            'amount_cents' => 14950,
        ]);
        $this->assertDatabaseHas('admin_audit_events', [
            'module' => 'billing',
            'action' => 'manual_payment_recorded',
            'is_critical' => true,
        ]);
    }

    public function test_super_admin_can_refund_only_a_confirmed_manual_payment(): void
    {
        $this->authenticateAdmin();
        $manualPayment = SubscriptionPayment::factory()->create([
            'provider' => 'admin_manual',
            'status' => 'succeeded',
            'paid_at' => now(),
        ]);

        $this->withSpaOrigin()->postJson("/api/v1/auth/admin/billing/payments/{$manualPayment->id}/refund", [
            'reference' => 'REFUND-BANK-001',
            'reason' => 'Cobro duplicado confirmado por tesorería.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'refunded')
            ->assertJsonPath('data.refund_reference', 'REFUND-BANK-001');

        $externalPayment = SubscriptionPayment::factory()->create([
            'clinic_subscription_id' => $manualPayment->clinic_subscription_id,
            'provider' => 'simulated',
        ]);
        $this->withSpaOrigin()->postJson("/api/v1/auth/admin/billing/payments/{$externalPayment->id}/refund", [
            'reference' => 'REFUND-EXTERNAL-001',
            'reason' => 'Este pago requiere al proveedor externo.',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('admin_audit_events', [
            'module' => 'billing',
            'action' => 'manual_payment_refunded',
            'is_critical' => true,
        ]);
    }

    private function authenticateAdmin(): void
    {
        User::factory()->superAdmin()->create(['email' => 'billing-admin@example.test', 'phone_number' => '+525512345678', 'password' => 'SecurePass123']);
        $login = $this->withSpaOrigin()->postJson('/api/v1/auth/login', ['country_code' => 'MX', 'phone_number' => '55 1234 5678', 'password' => 'SecurePass123', 'requested_role' => 'super_admin'])->assertAccepted();
        $verification = $this->withSpaOrigin()->postJson('/api/v1/auth/login/verify-otp', ['challenge_id' => $login->json('data.challenge_id'), 'code' => '123456'])->assertOk();
        $cookie = $verification->getCookie(config('session.cookie'));
        $this->withCookie($cookie->getName(), $cookie->getValue());
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }
}
