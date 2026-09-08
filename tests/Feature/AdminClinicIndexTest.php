<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\PatientProfile;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class AdminClinicIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
    }

    public function test_super_admin_receives_a_paginated_real_clinic_dashboard(): void
    {
        $clinics = Clinic::factory()->count(12)->create();
        PatientProfile::factory()->create(['clinic_id' => $clinics->first()->id]);
        $this->authenticateAdmin();

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/admin/clinics?per_page=5&page=2')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 12)
            ->assertJsonPath('summary.totals.clinics', 12)
            ->assertJsonPath('summary.totals.active_clinics', 12)
            ->assertJsonPath('summary.totals.patients', 1)
            ->assertJsonCount(3, 'summary.plan_distribution');
    }

    public function test_admin_clinic_filters_are_validated_and_applied_server_side(): void
    {
        Clinic::factory()->create([
            'name' => 'Clínica Andina',
            'country_code' => 'PE',
            'address' => 'Lima',
            'status' => 'suspended',
        ]);
        Clinic::factory()->create([
            'name' => 'Clínica Central',
            'country_code' => 'MX',
            'address' => 'Monterrey',
        ]);
        $this->authenticateAdmin();

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/admin/clinics?search=Andina&country_code=PE&status=suspended')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Clínica Andina')
            ->assertJsonPath('meta.total', 1);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/admin/clinics?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
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
