<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class AdminMembershipPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
    }

    public function test_super_admin_can_create_a_plan(): void
    {
        $this->authenticateAdmin();

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/admin/memberships/plans', [
                'key' => 'startup',
                'name' => 'Startup',
                'description' => 'Para clínicas en crecimiento',
                'monthly_price_usd' => 49.99,
                'annual_price_usd' => 499.00,
                'features' => ['facturación', 'agenda', 'reportes'],
                'publicly_selectable' => true,
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.key', 'startup')
            ->assertJsonPath('data.name', 'Startup')
            ->assertJsonPath('data.description', 'Para clínicas en crecimiento')
            ->assertJsonPath('data.monthly_price_usd_cents', 4999)
            ->assertJsonPath('data.annual_price_usd_cents', 49900)
            ->assertJsonPath('data.currency', config('memberships.currency'))
            ->assertJsonPath('data.features.0', 'facturación')
            ->assertJsonPath('data.publicly_selectable', true)
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('plans', ['key' => 'startup', 'monthly_price_cents' => 4999]);
    }

    public function test_plan_creation_validates_key_format_and_prices(): void
    {
        $this->authenticateAdmin();

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/admin/memberships/plans', [
                'key' => 'Key Invalida!',
                'name' => '',
                'monthly_price_usd' => -5,
                'annual_price_usd' => 'no-un-numero',
                'features' => 'no-es-arreglo',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['key', 'name', 'monthly_price_usd', 'annual_price_usd', 'features']);
    }

    public function test_plan_key_must_be_unique_when_creating(): void
    {
        Plan::factory()->create(['key' => 'basic', 'name' => 'Básico']);
        $this->authenticateAdmin();

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/admin/memberships/plans', [
                'key' => 'basic',
                'name' => 'Duplicado',
                'monthly_price_usd' => 10,
                'annual_price_usd' => 100,
                'features' => ['x'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['key']);
    }

    public function test_super_admin_can_update_a_plan(): void
    {
        $plan = Plan::factory()->create(['key' => 'basic', 'monthly_price_cents' => 2900]);
        $this->authenticateAdmin();

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/admin/memberships/plans/{$plan->id}", [
                'name' => 'Básico Plus',
                'monthly_price_usd' => 39.99,
                'features' => ['agenda', 'teleconsulta'],
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $plan->id)
            ->assertJsonPath('data.name', 'Básico Plus')
            ->assertJsonPath('data.monthly_price_usd_cents', 3999)
            ->assertJsonPath('data.features.1', 'teleconsulta');

        $this->assertDatabaseHas('plans', ['id' => $plan->id, 'name' => 'Básico Plus', 'monthly_price_cents' => 3999]);
    }

    public function test_super_admin_can_update_a_plan_with_partial_data(): void
    {
        $plan = Plan::factory()->create(['key' => 'basic', 'monthly_price_cents' => 2900, 'annual_price_cents' => 29000]);
        $this->authenticateAdmin();

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/admin/memberships/plans/{$plan->id}", [
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.monthly_price_usd_cents', 2900)
            ->assertJsonPath('data.key', 'basic');
    }

    public function test_update_plan_ignores_own_key_on_unique_check(): void
    {
        $plan = Plan::factory()->create(['key' => 'basic', 'name' => 'Básico']);
        $this->authenticateAdmin();

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/admin/memberships/plans/{$plan->id}", [
                'key' => 'basic',
                'name' => 'Básico Renombrado',
            ])
            ->assertOk()
            ->assertJsonPath('data.key', 'basic')
            ->assertJsonPath('data.name', 'Básico Renombrado');
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
