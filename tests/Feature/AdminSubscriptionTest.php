<?php

namespace Tests\Feature;

use App\Enums\BillingCycle;
use App\Enums\PlanKey;
use App\Enums\SubscriptionStatus;
use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\Plan;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class AdminSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
    }

    public function test_super_admin_can_create_a_subscription(): void
    {
        $clinic = Clinic::factory()->create(['name' => 'Clínica Aurora', 'country_code' => 'PE']);
        $plan = Plan::factory()->create(['key' => PlanKey::Basic->value, 'monthly_price_cents' => 2900]);
        $this->authenticateAdmin();

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/admin/memberships/subscriptions', [
                'clinic_id' => $clinic->id,
                'plan_id' => $plan->id,
                'billing_cycle' => BillingCycle::Monthly->value,
                'starts_at' => '2026-08-07',
                'auto_renew' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.clinic_id', $clinic->id)
            ->assertJsonPath('data.clinic', 'Clínica Aurora')
            ->assertJsonPath('data.country_code', 'PE')
            ->assertJsonPath('data.plan', PlanKey::Basic->value)
            ->assertJsonPath('data.plan_name', $plan->name)
            ->assertJsonPath('data.billing_cycle', 'monthly')
            ->assertJsonPath('data.status', SubscriptionStatus::Active->value)
            ->assertJsonPath('data.auto_renew', true)
            ->assertJsonPath('data.starts_at', Carbon::parse('2026-08-07')->startOfDay()->toIso8601String())
            ->assertJsonPath('data.monthly_usd_cents', 2900);

        $this->assertDatabaseHas('clinic_subscriptions', [
            'clinic_id' => $clinic->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active->value,
        ]);
    }

    public function test_subscription_creation_validates_required_fields(): void
    {
        $this->authenticateAdmin();

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/admin/memberships/subscriptions', [
                'clinic_id' => 999999,
                'plan_id' => 999999,
                'billing_cycle' => 'semanal',
                'starts_at' => 'ayer',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['clinic_id', 'plan_id', 'billing_cycle', 'starts_at']);
    }

    public function test_super_admin_can_upgrade_a_subscription(): void
    {
        $clinic = Clinic::factory()->create();
        $basic = Plan::factory()->create(['key' => PlanKey::Basic->value]);
        $professional = Plan::factory()->create(['key' => PlanKey::Professional->value]);
        $subscription = ClinicSubscription::factory()->create([
            'clinic_id' => $clinic->id,
            'plan_id' => $basic->id,
            'billing_cycle' => BillingCycle::Monthly,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addMonths(2),
        ]);
        $this->authenticateAdmin();

        $this->withSpaOrigin()
            ->postJson("/api/v1/auth/admin/memberships/subscriptions/{$subscription->id}/upgrade", [
                'plan_id' => $professional->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $subscription->id)
            ->assertJsonPath('data.plan', PlanKey::Professional->value)
            ->assertJsonPath('data.plan_name', $professional->name)
            ->assertJsonPath('data.status', SubscriptionStatus::Active->value);

        $this->assertDatabaseHas('clinic_subscriptions', [
            'id' => $subscription->id,
            'plan_id' => $professional->id,
        ]);
    }

    public function test_upgrade_validates_plan_exists(): void
    {
        $clinic = Clinic::factory()->create();
        $plan = Plan::factory()->create(['key' => PlanKey::Basic->value]);
        $subscription = ClinicSubscription::factory()->create([
            'clinic_id' => $clinic->id,
            'plan_id' => $plan->id,
        ]);
        $this->authenticateAdmin();

        $this->withSpaOrigin()
            ->postJson("/api/v1/auth/admin/memberships/subscriptions/{$subscription->id}/upgrade", [
                'plan_id' => 999999,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['plan_id']);
    }

    public function test_super_admin_can_cancel_a_subscription(): void
    {
        $clinic = Clinic::factory()->create();
        $plan = Plan::factory()->create(['key' => PlanKey::Basic->value]);
        $subscription = ClinicSubscription::factory()->create([
            'clinic_id' => $clinic->id,
            'plan_id' => $plan->id,
            'auto_renew' => true,
        ]);
        $this->authenticateAdmin();

        $this->withSpaOrigin()
            ->postJson("/api/v1/auth/admin/memberships/subscriptions/{$subscription->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.id', $subscription->id)
            ->assertJsonPath('data.status', SubscriptionStatus::Canceled->value)
            ->assertJsonPath('data.auto_renew', false);

        $this->assertDatabaseHas('clinic_subscriptions', [
            'id' => $subscription->id,
            'status' => SubscriptionStatus::Canceled->value,
            'auto_renew' => false,
        ]);
    }

    private function authenticateAdmin(): void
    {
        User::factory()->superAdmin()->create([
            'email' => 'admin@example.test',
            'phone_number' => '+525512345678',
            'password' => 'SecurePass123',
        ]);

        $login = $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'country_code' => 'MX',
            'phone_number' => '55 1234 5678',
            'password' => 'SecurePass123',
            'requested_role' => 'super_admin',
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
