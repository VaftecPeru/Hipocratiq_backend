<?php

namespace Tests\Feature\Auth;

use App\Enums\AuthenticationContext;
use App\Enums\RoleKey;
use App\Enums\RoleScope;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\ClinicSubscription;
use App\Models\DoctorProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class RoleAccessFlowTest extends TestCase
{
    use RefreshDatabase;

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();

        $this->password = Str::random(32);
        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
        Notification::fake();
    }

    #[DataProvider('webRoleCases')]
    public function test_each_web_role_completes_login_session_and_authorized_landing_flow(
        string $role,
        string $expectedContext,
        string $allowedPath,
        string $forbiddenPath,
    ): void {
        $user = $this->createUserForRole($role);
        $actorContext = $role === 'patient'
            ? AuthenticationContext::Patient->value
            : AuthenticationContext::Staff->value;

        $payload = [
            'purpose' => 'login',
            'client_type' => 'web',
            'actor_context' => $actorContext,
            'requested_role' => $role,
            'identifier' => $user->email,
        ];
        if ($actorContext === AuthenticationContext::Staff->value) {
            $payload['password'] = $this->password;
        }

        $challenge = $this->withSpaOrigin()
            ->postJson('/api/v1/auth/otp/request', $payload)
            ->assertAccepted()
            ->assertJsonPath('data.next_step', 'verify_otp');

        $verification = $this->withSpaOrigin()
            ->postJson('/api/v1/auth/otp/verify', [
                'purpose' => 'login',
                'client_type' => 'web',
                'challenge_id' => $challenge->json('data.challenge_id'),
                'code' => '123456',
            ])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.actor_context', $expectedContext);

        $cookie = $verification->getCookie(config('session.cookie'));
        $this->withCookie($cookie->getName(), $cookie->getValue());

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.client_type', 'web')
            ->assertJsonPath('data.actor_context', $expectedContext)
            ->assertJsonPath('data.user.id', $user->id);

        $this->withSpaOrigin()->getJson($allowedPath)->assertOk();
        $this->withSpaOrigin()->getJson($forbiddenPath)->assertForbidden();
    }

    #[DataProvider('mobileRoleCases')]
    public function test_each_supported_mobile_role_completes_login_token_and_workspace_access(
        string $role,
        string $expectedContext,
        string $allowedPath,
    ): void {
        $user = $this->createUserForRole($role);
        $actorContext = $role === 'patient'
            ? AuthenticationContext::Patient->value
            : AuthenticationContext::Staff->value;
        $deviceName = 'HIPOCRATIQ Test Device';
        $deviceId = 'hipocratiq-'.$role.'-device-001';

        $payload = [
            'purpose' => 'login',
            'client_type' => 'mobile',
            'actor_context' => $actorContext,
            'requested_role' => $role,
            'identifier' => $user->email,
            'device_name' => $deviceName,
            'device_id' => $deviceId,
        ];
        if ($actorContext === AuthenticationContext::Staff->value) {
            $payload['password'] = $this->password;
        }

        $challenge = $this->postJson('/api/v1/auth/otp/request', $payload)
            ->assertAccepted()
            ->assertJsonPath('data.next_step', 'verify_otp');

        $verification = $this->postJson('/api/v1/auth/otp/verify', [
            'purpose' => 'login',
            'client_type' => 'mobile',
            'challenge_id' => $challenge->json('data.challenge_id'),
            'code' => '123456',
            'device_name' => $deviceName,
            'device_id' => $deviceId,
        ])
            ->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.actor_context', $expectedContext)
            ->assertJsonPath('data.user.id', $user->id);

        $token = (string) $verification->json('data.access_token');

        $this->withMobileToken($token, $deviceName, $deviceId)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.client_type', 'mobile')
            ->assertJsonPath('data.actor_context', $expectedContext);

        $this->withMobileToken($token, $deviceName, $deviceId)
            ->getJson($allowedPath)
            ->assertOk();
    }

    public function test_super_admin_is_intentionally_web_only_on_mobile(): void
    {
        $user = $this->createUserForRole(RoleKey::SuperAdmin->value);

        $this->postJson('/api/v1/auth/otp/request', [
            'purpose' => 'login',
            'client_type' => 'mobile',
            'actor_context' => 'staff',
            'requested_role' => RoleKey::SuperAdmin->value,
            'identifier' => $user->email,
            'password' => $this->password,
            'device_name' => 'HIPOCRATIQ Test Device',
            'device_id' => 'hipocratiq-superadmin-device-001',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('requested_role');
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function webRoleCases(): array
    {
        return [
            'super admin' => ['super_admin', 'staff', '/api/v1/auth/admin/dashboard', '/api/v1/auth/doctor/dashboard'],
            'clinic admin' => ['clinic_admin', 'clinic_admin', '/api/v1/auth/clinic/dashboard', '/api/v1/auth/admin/dashboard'],
            'receptionist' => ['receptionist', 'receptionist', '/api/v1/auth/clinic/dashboard', '/api/v1/auth/clinic/epidemiology'],
            'doctor' => ['doctor', 'doctor', '/api/v1/auth/doctor/dashboard', '/api/v1/auth/clinic/dashboard'],
            'patient' => ['patient', 'patient', '/api/v1/auth/patient/profile', '/api/v1/auth/doctor/dashboard'],
        ];
    }

    /** @return array<string, array{string, string, string}> */
    public static function mobileRoleCases(): array
    {
        return [
            'clinic admin' => ['clinic_admin', 'clinic_admin', '/api/v1/auth/clinic/dashboard'],
            'receptionist' => ['receptionist', 'receptionist', '/api/v1/auth/clinic/dashboard'],
            'doctor' => ['doctor', 'doctor', '/api/v1/auth/doctor/dashboard'],
            'patient' => ['patient', 'patient', '/api/v1/auth/patient/profile'],
        ];
    }

    private function createUserForRole(string $role): User
    {
        $user = User::factory()->create([
            'email' => $role.'-access@example.test',
            'password' => $role === 'patient' ? null : $this->password,
        ]);

        if ($role === RoleKey::SuperAdmin->value) {
            $superAdmin = Role::query()->firstOrCreate(
                ['key' => RoleKey::SuperAdmin->value],
                ['name' => 'Super Administrador', 'scope' => RoleScope::Global, 'is_system' => true],
            );
            $user->roles()->attach($superAdmin);

            return $user;
        }

        $clinic = Clinic::factory()->create();
        ClinicSubscription::factory()->create(['clinic_id' => $clinic->id]);

        if ($role === 'patient') {
            $user->patientProfile()->create(['clinic_id' => $clinic->id]);

            return $user;
        }

        $roleModel = Role::query()->firstOrCreate(
            ['key' => $role],
            ['name' => Str::headline($role), 'scope' => RoleScope::Organization, 'is_system' => true],
        );
        $user->roles()->attach($roleModel);
        ClinicMembership::query()->create([
            'clinic_id' => $clinic->id,
            'user_id' => $user->id,
            'role_id' => $roleModel->id,
            'status' => 'active',
        ]);

        if ($role === RoleKey::Doctor->value) {
            DoctorProfile::factory()->create([
                'user_id' => $user->id,
                'clinic_id' => $clinic->id,
                'practice_type' => 'clinic',
            ]);
        }

        return $user;
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }

    private function withMobileToken(string $token, string $deviceName, string $deviceId): static
    {
        return $this->withToken($token)->withHeaders([
            'X-Client-Type' => 'mobile',
            'X-Device-Name' => $deviceName,
            'X-Device-ID' => $deviceId,
        ]);
    }
}
