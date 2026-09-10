<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class SuperAdminAuthorizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
    }

    public function test_super_admin_bypasses_authorization_gates(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->assertTrue(Gate::forUser($superAdmin)->allows('manage-platform'));
    }

    public function test_regular_user_does_not_receive_global_authorization(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(Gate::forUser($user)->allows('manage-platform'));
    }

    public function test_suspended_super_admin_does_not_receive_global_authorization(): void
    {
        $superAdmin = User::factory()->superAdmin()->create([
            'status' => UserStatus::Suspended,
        ]);

        $this->assertFalse(Gate::forUser($superAdmin)->allows('manage-platform'));
    }

    public function test_user_without_super_admin_role_cannot_start_staff_login(): void
    {
        User::factory()->create([
            'email' => 'staff@example.com',
            'password' => 'SecurePass123',
        ]);

        $this->withSpaOrigin()->postJson('/api/v1/auth/otp/request', [
            'purpose' => 'login',
            'identifier' => 'staff@example.com',
            'actor_context' => 'staff',
            'password' => 'SecurePass123',
        ])->assertUnprocessable()->assertJsonValidationErrors('identifier');

        $this->assertDatabaseCount('otp_challenges', 0);
    }

    public function test_super_admin_login_is_restricted_to_web_client(): void
    {
        User::factory()->superAdmin()->create([
            'email' => 'admin@example.com',
            'password' => 'SecurePass123',
        ]);

        $this->postJson('/api/v1/auth/otp/request', [
            'purpose' => 'login',
            'identifier' => 'admin@example.com',
            'actor_context' => 'staff',
            'client_type' => 'mobile',
            'requested_role' => 'super_admin',
            'password' => 'SecurePass123',
            'device_name' => 'Android',
            'device_id' => 'device-id',
        ])->assertUnprocessable()->assertJsonValidationErrors('requested_role');

        $this->assertSame(0, OtpChallenge::query()->count());
    }

    public function test_super_admin_cannot_bypass_password_using_patient_context(): void
    {
        $superAdmin = User::factory()->superAdmin()->create([
            'phone_number' => '+51900112844',
        ]);
        $superAdmin->patientProfile()->create();

        $this->withSpaOrigin()->postJson('/api/v1/auth/otp/request', [
            'purpose' => 'login',
            'identifier' => '+51900112844',
            'actor_context' => 'patient',
        ])->assertUnprocessable()->assertJsonValidationErrors('identifier');

        $this->assertDatabaseCount('otp_challenges', 0);
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }
}
