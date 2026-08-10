<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class AdminInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
    }

    public function test_super_admin_receives_truthful_backup_and_integration_capabilities(): void
    {
        $this->authenticateAdmin();

        $this->withSpaOrigin()->getJson('/api/v1/auth/admin/infrastructure')
            ->assertOk()
            ->assertJsonPath('data.backups.status', 'not_configured')
            ->assertJsonPath('data.backups.capabilities.restore', false)
            ->assertJsonPath('data.integrations.0.key', 'email')
            ->assertJsonPath('data.integrations.2.key', 'billing')
            ->assertJsonPath('data.integrations.5.status', 'not_configured')
            ->assertJsonCount(8, 'data.integrations');
    }

    private function authenticateAdmin(): void
    {
        User::factory()->superAdmin()->create([
            'email' => 'infrastructure-admin@example.test',
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
        $cookie = $verification->getCookie(config('session.cookie'));
        $this->withCookie($cookie->getName(), $cookie->getValue());
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }
}
