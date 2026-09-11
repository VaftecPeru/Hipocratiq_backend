<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class RoleMismatchMessageTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
    }

    public function test_staff_login_returns_specific_message_when_selected_role_does_not_match_account(): void
    {
        User::factory()->superAdmin()->create([
            'email' => 'superadmin@example.com',
            'email_verified_at' => now(),
            'password' => 'SecurePass123',
        ]);

        $this->withSpaOrigin()->postJson('/api/v1/auth/otp/request', [
            'purpose' => 'login',
            'identifier' => 'superadmin@example.com',
            'actor_context' => 'staff',
            'client_type' => 'web',
            'requested_role' => 'receptionist',
            'password' => 'SecurePass123',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('identifier')
            ->assertJsonPath(
                'errors.identifier.0',
                'El usuario no corresponde al rol seleccionado. Verifica el tipo de acceso e inténtalo nuevamente.'
            );

        $this->assertDatabaseCount('otp_challenges', 0);
        $this->assertDatabaseCount('authentication_sessions', 0);
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }
}
