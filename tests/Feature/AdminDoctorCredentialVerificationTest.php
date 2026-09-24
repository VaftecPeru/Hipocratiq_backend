<?php

namespace Tests\Feature;

use App\Models\DoctorProfile;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class AdminDoctorCredentialVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
    }

    public function test_super_admin_can_verify_mexican_professional_license_with_audit_history(): void
    {
        $this->authenticateAdmin();
        $doctor = User::factory()->create([
            'name' => 'Dra. Elena Ruiz',
            'country_code' => 'MX',
        ]);
        $profile = DoctorProfile::query()->create([
            'user_id' => $doctor->id,
            'medical_license' => '12345678',
            'medical_license_country_code' => 'MX',
            'medical_license_issuer' => 'SEP-DGP',
            'medical_license_verification_status' => 'unverified',
            'specialty' => 'Cardiología',
            'practice_type' => 'independent',
        ]);

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/admin/doctors/'.$profile->id.'/credentials/verify', [
                'credential_type' => 'professional',
                'status' => 'verified',
                'reference' => 'registro-profesional:12345678',
                'notes' => 'Validación administrativa documentada.',
            ])
            ->assertOk()
            ->assertJsonPath('data.medical_license', '12345678')
            ->assertJsonPath('data.medical_license_verification_status', 'verified')
            ->assertJsonPath('data.medical_license_verification_reference', 'registro-profesional:12345678');

        $this->assertDatabaseHas('doctor_credential_verifications', [
            'doctor_profile_id' => $profile->id,
            'credential_type' => 'professional',
            'credential_value' => '12345678',
            'status' => 'verified',
        ]);
        $this->assertDatabaseHas('admin_audit_events', [
            'module' => 'professional_credentials',
            'action' => 'doctor_credential_verified',
            'resource_id' => (string) $profile->id,
        ]);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/admin/doctors/'.$profile->id.'/credentials')
            ->assertOk()
            ->assertJsonPath('data.doctor.medical_license_verification_status', 'verified')
            ->assertJsonCount(1, 'data.history');
    }

    public function test_specialty_verification_rejects_doctor_without_specialty_license(): void
    {
        $this->authenticateAdmin();
        $doctor = User::factory()->create(['country_code' => 'MX']);
        $profile = DoctorProfile::query()->create([
            'user_id' => $doctor->id,
            'medical_license' => '87654321',
            'medical_license_country_code' => 'MX',
            'medical_license_issuer' => 'SEP-DGP',
            'medical_license_verification_status' => 'unverified',
            'specialty' => 'Medicina interna',
            'practice_type' => 'independent',
        ]);

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/admin/doctors/'.$profile->id.'/credentials/verify', [
                'credential_type' => 'specialty',
                'status' => 'verified',
                'reference' => 'registro-especialidad',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('credential_type');
    }

    private function authenticateAdmin(): void
    {
        $password = Str::random(32);
        User::factory()->superAdmin()->create([
            'phone_number' => '+525512345678',
            'password' => $password,
        ]);

        $login = $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'country_code' => 'MX',
            'phone_number' => '55 1234 5678',
            'password' => $password,
            'requested_role' => 'super_admin',
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
