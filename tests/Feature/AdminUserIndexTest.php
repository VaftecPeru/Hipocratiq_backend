<?php

namespace Tests\Feature;

use App\Enums\RoleKey;
use App\Enums\RoleScope;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\PatientProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class AdminUserIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
    }

    public function test_super_admin_receives_paginated_real_users_and_summary(): void
    {
        User::factory()->count(12)->create();
        $this->authenticateAdmin();

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/admin/users?per_page=5&page=2')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 13)
            ->assertJsonPath('summary.totals.users', 13)
            ->assertJsonPath('summary.totals.active_users', 13)
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'name',
                    'email',
                    'phone_number',
                    'country_code',
                    'role',
                    'clinic',
                    'last_login_at',
                    'status',
                    'created_at',
                ]],
                'summary' => ['totals', 'role_distribution', 'country_distribution', 'recent_activity'],
                'filter_options' => ['clinics'],
            ])
            ->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.remember_token');
    }

    public function test_admin_user_filters_are_validated_and_applied_server_side(): void
    {
        $clinic = Clinic::factory()->create(['name' => 'Clínica Andina', 'country_code' => 'PE']);
        $clinicAdminRole = $this->role(RoleKey::ClinicAdmin, 'Administrador de Clínica');
        $clinicAdmin = User::factory()->create([
            'name' => 'María Administradora',
            'email' => 'maria@andina.test',
            'country_code' => 'PE',
        ]);
        $clinicAdmin->roles()->attach($clinicAdminRole->id);
        ClinicMembership::factory()->create([
            'clinic_id' => $clinic->id,
            'user_id' => $clinicAdmin->id,
            'role_id' => $clinicAdminRole->id,
        ]);
        User::factory()->create(['name' => 'Usuario no relacionado', 'country_code' => 'MX']);
        $this->authenticateAdmin();

        $this->withSpaOrigin()
            ->getJson("/api/v1/auth/admin/users?search=Andina&country_code=PE&clinic_id={$clinic->id}&role=clinic_admin&status=active")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $clinicAdmin->id)
            ->assertJsonPath('data.0.role', RoleKey::ClinicAdmin->value)
            ->assertJsonPath('data.0.clinic.name', 'Clínica Andina')
            ->assertJsonPath('meta.total', 1);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/admin/users?per_page=101&role=invalid&status=invalid&clinic_id=999999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page', 'role', 'status', 'clinic_id']);
    }

    public function test_patient_role_and_clinic_are_derived_from_patient_profile(): void
    {
        $clinic = Clinic::factory()->create(['name' => 'Clínica Pacientes']);
        $patient = User::factory()->create(['name' => 'Paciente Real']);
        PatientProfile::factory()->create([
            'user_id' => $patient->id,
            'clinic_id' => $clinic->id,
        ]);
        $this->authenticateAdmin();

        $this->withSpaOrigin()
            ->getJson("/api/v1/auth/admin/users?role=patient&clinic_id={$clinic->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $patient->id)
            ->assertJsonPath('data.0.role', 'patient')
            ->assertJsonPath('data.0.clinic.id', $clinic->id);
    }

    private function role(RoleKey $key, string $name): Role
    {
        return Role::query()->firstOrCreate(
            ['key' => $key->value],
            [
                'name' => $name,
                'scope' => RoleScope::Organization,
                'is_system' => true,
            ],
        );
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
