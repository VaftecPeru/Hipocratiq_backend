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
        $this->withSpaOrigin()->get('/api/v1/auth/admin/reports/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertDatabaseHas('admin_audit_events', ['module' => 'billing', 'action' => 'billing_exported']);
        $this->assertDatabaseHas('admin_audit_events', ['module' => 'reports', 'action' => 'executive_report_exported']);
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
