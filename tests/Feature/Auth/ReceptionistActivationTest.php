<?php

namespace Tests\Feature\Auth;

use App\Enums\AuthenticationClient;
use App\Enums\AuthenticationContext;
use App\Enums\OnboardingStatus;
use App\Enums\RoleKey;
use App\Enums\RoleScope;
use App\Enums\UserStatus;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\ClinicSubscription;
use App\Models\PersonalAccessToken;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use App\Services\Sms\ArraySmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class ReceptionistActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
        $this->app->make(ArraySmsSender::class)->clear();
        Role::query()->create([
            'key' => RoleKey::Receptionist->value,
            'name' => 'Recepcionista',
            'scope' => RoleScope::Organization,
            'is_system' => true,
        ]);
    }

    public function test_clinic_admin_can_invite_and_activate_a_receptionist_on_mobile(): void
    {
        [$adminToken, $clinic] = $this->mobileClinicAdmin();
        $invitation = $this->withHeaders($this->mobileHeaders($adminToken, 'admin-device-0000'))
            ->postJson('/api/v1/auth/clinic/receptionist-invitations', [
                'name' => 'María López',
                'email' => 'maria.recepcion@example.test',
                'country_code' => 'MX',
                'phone_number' => '55 8765 4321',
            ])
            ->assertCreated()
            ->assertJsonPath('data.next_step', 'activate')
            ->json('data.invitation_token');

        $this->assertIsString($invitation);
        $this->assertDatabaseHas('receptionist_invitations', [
            'clinic_id' => $clinic->id,
            'token_hash' => hash('sha256', $invitation),
            'accepted_at' => null,
        ]);
        $this->assertDatabaseMissing('receptionist_invitations', [
            'token_hash' => $invitation,
        ]);

        $activation = $this->postJson('/api/v1/auth/receptionist/activation/request', [
            'invitation_token' => $invitation,
            'client_type' => 'mobile',
            'device_name' => 'Reception phone',
            'device_id' => 'reception-device-0001',
        ])->assertAccepted()
            ->assertJsonPath('data.resends_remaining', config('authentication.otp.max_resends'));

        $receptionist = User::query()->where('email', 'maria.recepcion@example.test')->sole();
        $this->assertSame(UserStatus::PendingVerification, $receptionist->status);
        $this->assertSame(OnboardingStatus::PhoneVerification, $receptionist->onboarding_status);

        $verification = $this->postJson('/api/v1/auth/receptionist/activation/verify', [
            'invitation_token' => $invitation,
            'client_type' => 'mobile',
            'challenge_id' => $activation->json('data.challenge_id'),
            'code' => '123456',
            'device_name' => 'Reception phone',
            'device_id' => 'reception-device-0001',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
        ])->assertOk();

        $verification
            ->assertJsonPath('data.actor_context', AuthenticationContext::Receptionist->value)
            ->assertJsonPath('data.user.id', $receptionist->id);
        $receptionist = $receptionist->fresh();
        $this->assertSame(UserStatus::Active, $receptionist->status);
        $this->assertSame(OnboardingStatus::Completed, $receptionist->onboarding_status);
        $this->assertTrue(Hash::check('SecurePass123', $receptionist->password));
        $this->assertDatabaseHas('clinic_memberships', [
            'clinic_id' => $clinic->id,
            'user_id' => $receptionist->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('receptionist_invitations', [
            'token_hash' => hash('sha256', $invitation),
        ]);

        $token = PersonalAccessToken::query()
            ->where('tokenable_id', $receptionist->id)
            ->sole();
        $this->assertSame(AuthenticationClient::Mobile, $token->client_type);
        $this->assertSame(AuthenticationContext::Receptionist, $token->actor_context);
        $this->assertSame(hash('sha256', 'reception-device-0001'), $token->device_id_hash);
    }

    public function test_receptionist_activation_rejects_a_different_device(): void
    {
        [$adminToken] = $this->mobileClinicAdmin();
        $invitation = $this->withHeaders($this->mobileHeaders($adminToken, 'admin-device-0000'))
            ->postJson('/api/v1/auth/clinic/receptionist-invitations', [
                'name' => 'Carlos Ruiz',
                'email' => 'carlos.recepcion@example.test',
                'country_code' => 'MX',
                'phone_number' => '55 8765 4322',
            ])
            ->assertCreated()
            ->json('data.invitation_token');

        $activation = $this->postJson('/api/v1/auth/receptionist/activation/request', [
            'invitation_token' => $invitation,
            'client_type' => 'mobile',
            'device_name' => 'Reception phone',
            'device_id' => 'reception-device-0002',
        ])->assertAccepted();

        $this->postJson('/api/v1/auth/receptionist/activation/verify', [
            'invitation_token' => $invitation,
            'client_type' => 'mobile',
            'challenge_id' => $activation->json('data.challenge_id'),
            'code' => '123456',
            'device_name' => 'Another phone',
            'device_id' => 'reception-device-0003',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $receptionist = User::query()->where('email', 'carlos.recepcion@example.test')->sole();
        $this->assertSame(UserStatus::PendingVerification, $receptionist->status);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_mobile_clinic_admin_must_use_an_invitation_to_create_a_receptionist(): void
    {
        [$adminToken] = $this->mobileClinicAdmin();

        $this->withHeaders($this->mobileHeaders($adminToken, 'admin-device-0000'))
            ->postJson('/api/v1/auth/clinic/members', [
                'name' => 'Recepción directa',
                'email' => 'direct.reception@example.test',
                'country_code' => 'MX',
                'phone_number' => '55 8765 4323',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', [
            'email' => 'direct.reception@example.test',
        ]);
        $this->assertDatabaseCount('receptionist_invitations', 0);
    }

    /** @return array{0: string, 1: Clinic} */
    private function mobileClinicAdmin(): array
    {
        $clinic = Clinic::factory()->create();
        ClinicSubscription::factory()->create([
            'clinic_id' => $clinic->id,
            'plan_id' => Plan::factory(),
        ]);
        $role = Role::query()->create([
            'key' => RoleKey::ClinicAdmin->value,
            'name' => 'Administrador Clínico',
            'scope' => RoleScope::Organization,
            'is_system' => true,
        ]);
        $admin = User::factory()->create([
            'email' => 'admin.mobile@example.test',
            'phone_number' => '+525587650001',
            'password' => 'SecurePass123',
        ]);
        $admin->roles()->attach($role);
        ClinicMembership::query()->create([
            'clinic_id' => $clinic->id,
            'user_id' => $admin->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $login = $this->postJson('/api/v1/auth/otp/request', [
            'purpose' => 'login',
            'identifier' => $admin->email,
            'actor_context' => 'staff',
            'requested_role' => RoleKey::ClinicAdmin->value,
            'client_type' => 'mobile',
            'password' => 'SecurePass123',
            'device_name' => 'Admin phone',
            'device_id' => 'admin-device-0000',
        ])->assertAccepted();
        $token = $this->postJson('/api/v1/auth/otp/verify', [
            'purpose' => 'login',
            'challenge_id' => $login->json('data.challenge_id'),
            'code' => '123456',
            'client_type' => 'mobile',
            'device_name' => 'Admin phone',
            'device_id' => 'admin-device-0000',
        ])->assertOk()->json('data.access_token');

        return [$token, $clinic];
    }

    /** @return array<string, string> */
    private function mobileHeaders(string $token, string $deviceId): array
    {
        return [
            'Authorization' => "Bearer {$token}",
            'X-Client-Type' => 'mobile',
            'X-Device-Name' => 'Admin phone',
            'X-Device-ID' => $deviceId,
        ];
    }
}
