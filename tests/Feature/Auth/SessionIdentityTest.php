<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleKey;
use App\Enums\RoleScope;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\ClinicSubscription;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class SessionIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Role $clinicAdminRole;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
        $this->clinicAdminRole = Role::query()->create([
            'key' => RoleKey::ClinicAdmin->value,
            'name' => 'Clinic administrator',
            'scope' => RoleScope::Organization,
            'is_system' => true,
        ]);
        $this->plan = Plan::query()->create([
            'key' => 'basic',
            'name' => 'Basic',
            'monthly_price_cents' => 4900,
            'annual_price_cents' => 47000,
            'currency' => 'USD',
            'features' => [],
            'publicly_selectable' => true,
            'is_active' => true,
        ]);
    }

    public function test_auth_me_uses_the_current_context_eligible_clinic(): void
    {
        $user = $this->createClinicAdmin('+525511223344');
        $eligibleClinic = $this->createClinic();
        $futureClinic = $this->createClinic(now()->addDay());
        $this->attachMembership($user, $eligibleClinic);
        $this->attachMembership($user, $futureClinic);
        $this->authenticate($user, '55 1122 3344');

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.actor_context', 'clinic_admin')
            ->assertJsonPath('data.user.clinic.id', $eligibleClinic->id);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/clinic/dashboard')
            ->assertOk()
            ->assertJsonPath('data.clinic.id', $eligibleClinic->id);
    }

    public function test_auth_me_does_not_choose_an_arbitrary_clinic_for_multiple_eligible_memberships(): void
    {
        $user = $this->createClinicAdmin('+525511223355');
        $firstClinic = $this->createClinic();
        $secondClinic = $this->createClinic();
        $this->attachMembership($user, $firstClinic);
        $this->attachMembership($user, $secondClinic);
        $this->authenticate($user, '55 1122 3355');

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.clinic', null);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/clinic/dashboard')
            ->assertForbidden()
            ->assertJsonPath('code', 'CLINIC_CONTEXT_REQUIRED');

        $this->withSpaOrigin()
            ->patchJson('/api/v1/auth/clinic/settings', ['name' => 'Cambio ambiguo'])
            ->assertForbidden()
            ->assertJsonPath('code', 'CLINIC_CONTEXT_REQUIRED');

        $this->assertSame($firstClinic->name, $firstClinic->fresh()->name);
        $this->assertSame($secondClinic->name, $secondClinic->fresh()->name);
    }

    public function test_receptionist_with_multiple_eligible_clinics_cannot_read_or_register_patients(): void
    {
        $role = Role::query()->create([
            'key' => RoleKey::Receptionist->value,
            'name' => 'Receptionist',
            'scope' => RoleScope::Organization,
            'is_system' => true,
        ]);
        $user = User::factory()->create([
            'phone_number' => '+525511223366',
            'password' => 'SecurePass123',
        ]);
        $user->roles()->attach($role);
        foreach ([$this->createClinic(), $this->createClinic()] as $clinic) {
            ClinicMembership::query()->create([
                'clinic_id' => $clinic->id,
                'user_id' => $user->id,
                'role_id' => $role->id,
                'status' => 'active',
            ]);
        }
        $this->authenticate($user, '55 1122 3366', RoleKey::Receptionist);

        $this->withSpaOrigin()->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('data.user.clinic', null);
        $this->withSpaOrigin()->getJson('/api/v1/auth/clinic/patients')
            ->assertForbidden()->assertJsonPath('code', 'CLINIC_CONTEXT_REQUIRED');
        $this->withSpaOrigin()->postJson('/api/v1/auth/clinic/patients', [
            'name' => 'Paciente ambiguo',
            'email' => 'ambiguous-patient@example.test',
            'phone_number' => '55 1122 3377',
            'country_code' => 'MX',
        ])->assertForbidden()->assertJsonPath('code', 'CLINIC_CONTEXT_REQUIRED');

        $this->assertDatabaseMissing('users', ['email' => 'ambiguous-patient@example.test']);
        $this->assertDatabaseCount('patient_profiles', 0);
    }

    public function test_resolving_an_ambiguous_membership_restores_the_same_clinic_in_session_and_api(): void
    {
        $user = $this->createClinicAdmin('+525511223388');
        $firstClinic = $this->createClinic();
        $secondClinic = $this->createClinic();
        $this->attachMembership($user, $firstClinic);
        $this->attachMembership($user, $secondClinic);
        $this->authenticate($user, '55 1122 3388');

        $this->withSpaOrigin()->getJson('/api/v1/auth/clinic/dashboard')
            ->assertForbidden()->assertJsonPath('code', 'CLINIC_CONTEXT_REQUIRED');
        $user->clinicMemberships()->where('clinic_id', $firstClinic->id)->update(['status' => 'suspended']);

        $this->withSpaOrigin()->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('data.user.clinic.id', $secondClinic->id);
        $this->withSpaOrigin()->getJson('/api/v1/auth/clinic/dashboard')
            ->assertOk()->assertJsonPath('data.clinic.id', $secondClinic->id);
    }

    private function createClinic(?\DateTimeInterface $startsAt = null): Clinic
    {
        $clinic = Clinic::factory()->create();
        ClinicSubscription::factory()->create([
            'clinic_id' => $clinic->id,
            'plan_id' => $this->plan->id,
            'starts_at' => $startsAt ?? now(),
        ]);

        return $clinic;
    }

    private function createClinicAdmin(string $phoneNumber): User
    {
        $user = User::factory()->create([
            'phone_number' => $phoneNumber,
            'password' => 'SecurePass123',
        ]);
        $user->roles()->attach($this->clinicAdminRole);

        return $user;
    }

    private function attachMembership(User $user, Clinic $clinic): void
    {
        ClinicMembership::query()->create([
            'clinic_id' => $clinic->id,
            'user_id' => $user->id,
            'role_id' => $this->clinicAdminRole->id,
            'status' => 'active',
        ]);
    }

    private function authenticate(User $user, string $phoneNumber, RoleKey $role = RoleKey::ClinicAdmin): void
    {
        $login = $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'country_code' => 'MX',
            'phone_number' => $phoneNumber,
            'password' => 'SecurePass123',
            'requested_role' => $role->value,
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
