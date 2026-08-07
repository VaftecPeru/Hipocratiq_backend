<?php

namespace Tests\Feature;

use App\Enums\BillingCycle;
use App\Enums\PlanKey;
use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\Plan;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class AdminMembershipIndexTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Plan> */
    private array $plans = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
    }

    public function test_super_admin_receives_membership_dashboard(): void
    {
        $this->seedPlans();

        $clinicPeru = Clinic::factory()->create(['country_code' => 'PE']);
        $clinicMexico = Clinic::factory()->create(['country_code' => 'MX']);
        $clinicChile = Clinic::factory()->create(['country_code' => 'CL']);

        ClinicSubscription::factory()->create([
            'clinic_id' => $clinicPeru->id,
            'plan_id' => $this->plan(PlanKey::Basic)->id,
            'billing_cycle' => BillingCycle::Monthly,
            'auto_renew' => true,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addDays(10),
        ]);
        ClinicSubscription::factory()->create([
            'clinic_id' => $clinicMexico->id,
            'plan_id' => $this->plan(PlanKey::Professional)->id,
            'billing_cycle' => BillingCycle::Annual,
            'auto_renew' => false,
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->addDays(20),
        ]);
        ClinicSubscription::factory()->create([
            'clinic_id' => $clinicChile->id,
            'plan_id' => $this->plan(PlanKey::Enterprise)->id,
            'billing_cycle' => BillingCycle::Monthly,
            'auto_renew' => true,
            'starts_at' => now()->subDays(5),
            'ends_at' => null,
        ]);
        ClinicSubscription::factory()->create([
            'clinic_id' => $clinicMexico->id,
            'plan_id' => $this->plan(PlanKey::Basic)->id,
            'status' => 'canceled',
            'billing_cycle' => BillingCycle::Monthly,
            'auto_renew' => false,
            'ends_at' => now()->subDay(),
        ]);
        $this->authenticateAdmin();

        $response = $this->withSpaOrigin()
            ->getJson('/api/v1/auth/admin/memberships')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'kpis' => [
                        'mrr' => ['usd_cents', 'pen_cents', 'change_pct', 'change_note'],
                        'arr' => ['usd_cents', 'pen_cents', 'change_pct', 'change_note'],
                        'active_subscriptions' => ['count', 'change_count', 'change_note'],
                        'renewal_rate' => ['percentage', 'change_pct', 'change_note'],
                    ],
                    'plans' => [[
                        'id', 'key', 'name', 'description',
                        'monthly_price_usd_cents', 'annual_price_usd_cents',
                        'currency', 'features', 'publicly_selectable', 'is_active',
                        'subscribers', 'popular',
                    ]],
                    'subscriptions_by_country' => [['country_code', 'count', 'percentage']],
                    'subscriptions_by_plan',
                    'upcoming_renewals',
                    'conversion_rate',
                    'generated_at',
                ],
            ])
            ->assertJsonPath('data.kpis.active_subscriptions.count', 3)
            ->assertJsonPath('data.kpis.active_subscriptions.change_count', 1);
    }

    public function test_mrr_arr_and_renewal_rate_are_computed_from_active_subscriptions(): void
    {
        $this->seedPlans();

        $clinic = Clinic::factory()->create(['country_code' => 'PE']);

        $basicMonthly = ClinicSubscription::factory()->create([
            'clinic_id' => $clinic->id,
            'plan_id' => $this->plan(PlanKey::Basic)->id,
            'billing_cycle' => BillingCycle::Monthly,
            'auto_renew' => true,
        ]);
        $professionalAnnual = ClinicSubscription::factory()->create([
            'clinic_id' => $clinic->id,
            'plan_id' => $this->plan(PlanKey::Professional)->id,
            'billing_cycle' => BillingCycle::Annual,
            'auto_renew' => true,
        ]);
        $this->authenticateAdmin();

        $basicMonthlyCents = 2900;
        $professionalAnnualCents = 69000;
        $expectedMrr = $basicMonthlyCents + (int) round($professionalAnnualCents / 12);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/admin/memberships')
            ->assertOk()
            ->assertJsonPath('data.kpis.mrr.usd_cents', $expectedMrr)
            ->assertJsonPath('data.kpis.arr.usd_cents', $expectedMrr * 12)
            ->assertJsonPath('data.kpis.renewal_rate.percentage', 100)
            ->assertJsonPath('data.conversion_rate', 3.77);
    }

    public function test_upcoming_renewals_only_include_renewals_in_window(): void
    {
        $this->seedPlans();

        $clinic = Clinic::factory()->create(['country_code' => 'PE']);

        ClinicSubscription::factory()->create([
            'clinic_id' => $clinic->id,
            'plan_id' => $this->plan(PlanKey::Basic)->id,
            'billing_cycle' => BillingCycle::Monthly,
            'auto_renew' => true,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addDays(15),
        ]);
        ClinicSubscription::factory()->create([
            'clinic_id' => $clinic->id,
            'plan_id' => $this->plan(PlanKey::Professional)->id,
            'billing_cycle' => BillingCycle::Monthly,
            'auto_renew' => true,
            'starts_at' => now()->subDays(40),
            'ends_at' => now()->addDays(45),
        ]);
        $this->authenticateAdmin();

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/admin/memberships')
            ->assertOk()
            ->assertJsonCount(1, 'data.upcoming_renewals')
            ->assertJsonPath('data.upcoming_renewals.0.renewal_at', now()->addDays(15)->toDateString())
            ->assertJsonPath('data.upcoming_renewals.0.amount_usd_cents', 2900);
    }

    private function plan(PlanKey $key): Plan
    {
        return $this->plans[$key->value];
    }

    private function seedPlans(): void
    {
        $this->plans = [
            PlanKey::Basic->value => Plan::factory()->create([
                'key' => PlanKey::Basic->value,
                'name' => 'Básico',
                'monthly_price_cents' => 2900,
                'annual_price_cents' => 29000,
                'features' => ['registro de pacientes', 'agenda online'],
            ]),
            PlanKey::Professional->value => Plan::factory()->create([
                'key' => PlanKey::Professional->value,
                'name' => 'Profesional',
                'monthly_price_cents' => 6900,
                'annual_price_cents' => 69000,
                'features' => ['todo lo de Básico', 'teleconsulta', 'reportes'],
            ]),
            PlanKey::Enterprise->value => Plan::factory()->create([
                'key' => PlanKey::Enterprise->value,
                'name' => 'Empresa',
                'monthly_price_cents' => 14900,
                'annual_price_cents' => 149000,
                'features' => ['todo lo de Profesional', 'despliegue dedicado'],
            ]),
        ];
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
