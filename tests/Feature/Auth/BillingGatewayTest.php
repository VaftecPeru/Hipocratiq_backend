<?php

namespace Tests\Feature\Auth;

use App\Billing\BillingReceipt;
use App\Billing\SubscriptionPurchase;
use App\Contracts\BillingGateway;
use App\Enums\RoleKey;
use App\Enums\RoleScope;
use App\Exceptions\BillingUnavailableException;
use App\Models\Plan;
use App\Models\Role;
use App\Services\Auth\OtpCodeGenerator;
use App\Services\Billing\SimulatedBillingGateway;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class BillingGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
        Role::query()->create([
            'key' => RoleKey::ClinicAdmin->value,
            'name' => 'Administrador Clínico',
            'scope' => RoleScope::Organization,
            'is_system' => true,
        ]);
        Role::query()->create([
            'key' => RoleKey::Doctor->value,
            'name' => 'Médico',
            'scope' => RoleScope::Organization,
            'is_system' => true,
        ]);
        Plan::query()->create([
            'key' => 'basic',
            'name' => 'Básico',
            'monthly_price_cents' => 4900,
            'annual_price_cents' => 47000,
            'currency' => 'USD',
            'features' => [],
            'publicly_selectable' => true,
            'is_active' => true,
        ]);
    }

    public function test_unavailable_billing_keeps_clinic_otp_retriable_and_creates_no_domain_records(): void
    {
        $draft = $this->withSpaOrigin()->postJson(
            '/api/v1/auth/register/clinic/draft',
            $this->clinicPayload(),
        );
        $cookie = $draft->getCookie('clinic_registration');
        $plan = $this->withCookie($cookie->getName(), $cookie->getValue())
            ->withSpaOrigin()
            ->postJson('/api/v1/auth/register/clinic/plan', [
                'plan' => 'basic',
                'billing_cycle' => 'monthly',
            ]);
        $this->useBillingDriver('unavailable');

        $this->withCookie($cookie->getName(), $cookie->getValue())
            ->withSpaOrigin()
            ->postJson('/api/v1/auth/register/clinic/verify-otp', [
                'challenge_id' => $plan->json('data.challenge_id'),
                'code' => '123456',
            ])
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'BILLING_UNAVAILABLE');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('clinics', 0);
        $this->assertDatabaseCount('clinic_subscriptions', 0);
        $this->assertDatabaseCount('subscription_payments', 0);

        $this->useBillingDriver('simulated');
        $this->withCookie($cookie->getName(), $cookie->getValue())
            ->withSpaOrigin()
            ->postJson('/api/v1/auth/register/clinic/verify-otp', [
                'challenge_id' => $plan->json('data.challenge_id'),
                'code' => '123456',
            ])
            ->assertOk();

        $this->assertDatabaseCount('clinics', 1);
        $this->assertDatabaseCount('subscription_payments', 1);
    }

    public function test_unavailable_billing_keeps_independent_doctor_otp_retriable(): void
    {
        $draft = $this->withSpaOrigin()->postJson(
            '/api/v1/auth/register/doctor/draft',
            $this->doctorPayload(),
        );
        $cookie = $draft->getCookie('doctor_registration');
        $plan = $this->withCookie($cookie->getName(), $cookie->getValue())
            ->withSpaOrigin()
            ->postJson('/api/v1/auth/register/doctor/plan', [
                'plan' => 'basic',
                'billing_cycle' => 'monthly',
            ]);
        $this->useBillingDriver('unavailable');

        $this->withCookie($cookie->getName(), $cookie->getValue())
            ->withSpaOrigin()
            ->postJson('/api/v1/auth/register/doctor/verify-otp', [
                'challenge_id' => $plan->json('data.challenge_id'),
                'code' => '123456',
            ])
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'BILLING_UNAVAILABLE');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('doctor_profiles', 0);
        $this->assertDatabaseCount('doctor_subscriptions', 0);

        $this->useBillingDriver('simulated');
        $this->withCookie($cookie->getName(), $cookie->getValue())
            ->withSpaOrigin()
            ->postJson('/api/v1/auth/register/doctor/verify-otp', [
                'challenge_id' => $plan->json('data.challenge_id'),
                'code' => '123456',
            ])
            ->assertOk();

        $this->assertDatabaseCount('doctor_subscriptions', 1);
    }

    public function test_billing_gateway_runs_outside_the_registration_transaction(): void
    {
        $gateway = new class implements BillingGateway
        {
            /** @var list<int> */
            public array $transactionLevels = [];

            public function purchase(SubscriptionPurchase $purchase): BillingReceipt
            {
                $this->transactionLevels[] = DB::transactionLevel();

                return new BillingReceipt(
                    provider: 'test',
                    subscriptionReference: 'test-subscription-reference',
                    paymentReference: 'test-payment-reference',
                    paidAt: CarbonImmutable::now(),
                );
            }
        };
        $this->app->instance(BillingGateway::class, $gateway);
        $draft = $this->withSpaOrigin()->postJson(
            '/api/v1/auth/register/clinic/draft',
            $this->clinicPayload(),
        );
        $cookie = $draft->getCookie('clinic_registration');
        $plan = $this->withCookie($cookie->getName(), $cookie->getValue())
            ->withSpaOrigin()
            ->postJson('/api/v1/auth/register/clinic/plan', [
                'plan' => 'basic',
                'billing_cycle' => 'monthly',
            ]);
        $testTransactionLevel = DB::transactionLevel();

        $this->withCookie($cookie->getName(), $cookie->getValue())
            ->withSpaOrigin()
            ->postJson('/api/v1/auth/register/clinic/verify-otp', [
                'challenge_id' => $plan->json('data.challenge_id'),
                'code' => '123456',
            ])
            ->assertOk();

        $this->assertSame([$testTransactionLevel], $gateway->transactionLevels);
        $this->assertDatabaseHas('clinic_subscriptions', ['provider' => 'test']);
        $this->assertDatabaseHas('subscription_payments', ['provider' => 'test']);
    }

    public function test_simulated_gateway_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->expectException(BillingUnavailableException::class);

        (new SimulatedBillingGateway)->purchase(new SubscriptionPurchase(
            idempotencyKey: 'production-attempt',
            amountCents: 4900,
            currency: 'USD',
            subjectType: 'clinic',
            planKey: 'basic',
            billingCycle: 'monthly',
        ));
    }

    private function useBillingDriver(string $driver): void
    {
        config()->set('billing.driver', $driver);
        $this->app->forgetInstance(BillingGateway::class);
    }

    /** @return array<string, mixed> */
    private function clinicPayload(): array
    {
        return [
            'clinic_name' => 'Clínica Santa Elena',
            'tax_id' => 'RFC-123456',
            'country_code' => 'MX',
            'contact_phone' => '55 1234 5678',
            'address' => 'Calle Principal 123, Ciudad de México',
            'admin_name' => 'Ana Martínez',
            'admin_email' => 'ana@clinica.test',
            'admin_phone_number' => '55 8765 4321',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
            'terms_accepted' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function doctorPayload(): array
    {
        return [
            'doctor_name' => 'Dra. Elena Ruiz',
            'doctor_email' => 'medico@example.test',
            'country_code' => 'MX',
            'doctor_phone_number' => '55 1122 3344',
            'medical_license' => '12345678',
            'specialty' => 'Cardiología',
            'practice_type' => 'independent',
            'clinic_id' => null,
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
            'terms_accepted' => true,
        ];
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }
}
