<?php

namespace Tests\Feature;

use App\Enums\ClinicMembershipStatus;
use App\Enums\RoleKey;
use App\Enums\RoleScope;
use App\Models\Clinic;
use App\Models\PatientProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class AdminAccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
        $this->createSystemRoles();
    }

    public function test_super_admin_can_view_role_matrix_clinics_and_security_summary(): void
    {
        $admin = $this->authenticateAdmin();
        Clinic::factory()->create(['name' => 'Clínica Norte']);
        User::factory()->create();

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/admin/access-control')
            ->assertOk()
            ->assertJsonPath('data.actor_user_id', $admin->id)
            ->assertJsonPath('data.enforcement', 'actor_context')
            ->assertJsonCount(4, 'data.roles')
            ->assertJsonPath('data.roles.0.key', 'super_admin')
            ->assertJsonPath('data.roles.0.scope', 'global')
            ->assertJsonPath('data.summary.total_users', 2)
            ->assertJsonPath('data.summary.active_clinics', 1)
            ->assertJsonStructure([
                'data' => [
                    'roles' => [['key', 'name', 'scope', 'description', 'permissions', 'user_count']],
                    'permissions' => [['key', 'label', 'group']],
                    'clinics' => [['id', 'name', 'country_code', 'status']],
                    'recent_changes',
                ],
            ]);
    }

    public function test_assigning_clinic_admin_updates_membership_revokes_sessions_and_is_audited(): void
    {
        $admin = $this->authenticateAdmin();
        $clinic = Clinic::factory()->create();
        $user = User::factory()->create();
        $user->createToken('existing-token');
        $user->authenticationSessions()->create([
            'id' => (string) Str::uuid(),
            'session_id_hash' => hash('sha256', 'existing-session'),
            'actor_context' => 'patient',
            'last_seen_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/admin/users/{$user->id}/access", [
                'role' => RoleKey::ClinicAdmin->value,
                'clinic_id' => $clinic->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.role', 'clinic_admin')
            ->assertJsonPath('data.clinic.id', $clinic->id)
            ->assertJsonPath('data.changed', true)
            ->assertJsonPath('data.sessions_revoked', true);

        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->id,
            'role_id' => $this->role(RoleKey::ClinicAdmin)->id,
        ]);
        $this->assertDatabaseHas('clinic_memberships', [
            'user_id' => $user->id,
            'clinic_id' => $clinic->id,
            'role_id' => $this->role(RoleKey::ClinicAdmin)->id,
            'status' => ClinicMembershipStatus::Active->value,
        ]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('authentication_sessions', 1);
        $this->assertDatabaseHas('admin_audit_events', [
            'actor_user_id' => $admin->id,
            'module' => 'access_control',
            'action' => 'user_access_updated',
            'resource_id' => (string) $user->id,
            'is_critical' => true,
        ]);
    }

    public function test_patient_can_be_reassigned_only_when_a_patient_profile_exists(): void
    {
        $this->authenticateAdmin();
        $clinic = Clinic::factory()->create();
        $patient = User::factory()->create();
        PatientProfile::factory()->create(['user_id' => $patient->id]);
        $userWithoutProfile = User::factory()->create();

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/admin/users/{$patient->id}/access", [
                'role' => 'patient',
                'clinic_id' => $clinic->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.role', 'patient');

        $this->assertDatabaseHas('patient_profiles', [
            'user_id' => $patient->id,
            'clinic_id' => $clinic->id,
        ]);

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/admin/users/{$userWithoutProfile->id}/access", [
                'role' => 'patient',
                'clinic_id' => $clinic->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    public function test_super_admin_cannot_change_own_access_or_assign_an_inactive_clinic(): void
    {
        $admin = $this->authenticateAdmin();
        $inactiveClinic = Clinic::factory()->create(['status' => 'suspended']);
        $user = User::factory()->create();

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/admin/users/{$admin->id}/access", [
                'role' => 'clinic_admin',
                'clinic_id' => $inactiveClinic->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('clinic_id');

        $activeClinic = Clinic::factory()->create();
        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/admin/users/{$admin->id}/access", [
                'role' => 'clinic_admin',
                'clinic_id' => $activeClinic->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->withSpaOrigin()
            ->patchJson("/api/v1/auth/admin/users/{$user->id}/access", [
                'role' => 'super_admin',
                'clinic_id' => $activeClinic->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('clinic_id');
    }

    /** @return array<string, Role> */
    private function createSystemRoles(): array
    {
        return collect([
            RoleKey::SuperAdmin->value => ['Super Administrador', RoleScope::Global],
            RoleKey::ClinicAdmin->value => ['Administrador Clínico', RoleScope::Organization],
            RoleKey::Doctor->value => ['Médico', RoleScope::Organization],
        ])->mapWithKeys(function (array $definition, string $key): array {
            return [$key => Role::query()->firstOrCreate(
                ['key' => $key],
                ['name' => $definition[0], 'scope' => $definition[1], 'is_system' => true],
            )];
        })->all();
    }

    private function role(RoleKey $key): Role
    {
        return Role::query()->where('key', $key->value)->firstOrFail();
    }

    private function authenticateAdmin(): User
    {
        $admin = User::factory()->superAdmin()->create([
            'email' => 'access-admin@example.test',
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

        return $admin;
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }
}
