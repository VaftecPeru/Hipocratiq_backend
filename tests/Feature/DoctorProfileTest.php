<?php

namespace Tests\Feature;

use App\Enums\RoleKey;
use App\Enums\RoleScope;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\ClinicSubscription;
use App\Models\DoctorProfile;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Specialty;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class DoctorProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
        Role::query()->create(['key' => RoleKey::Doctor->value, 'name' => 'Médico', 'scope' => RoleScope::Organization, 'is_system' => true]);
        Plan::query()->create(['key' => 'basic', 'name' => 'Basic', 'monthly_price_cents' => 4900, 'annual_price_cents' => 47000, 'currency' => 'USD', 'features' => [], 'publicly_selectable' => true, 'is_active' => true]);
        $this->seed(SpecialtySeeder::class);
    }

    public function test_registration_persists_catalog_selection_and_rejects_mismatched_subspecialty(): void
    {
        $internalMedicine = Specialty::query()->where('code', 'medicina_interna')->sole();
        $cardiology = Specialty::query()->where('code', 'cardiologia')->sole();
        $pediatrics = Specialty::query()->where('code', 'pediatria')->sole();

        $this->withSpaOrigin()->postJson('/api/v1/auth/register/doctor/draft', $this->payload([
            'specialty_id' => $internalMedicine->id,
            'subspecialty_id' => $pediatrics->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('subspecialty_id');

        $draft = $this->withSpaOrigin()->postJson('/api/v1/auth/register/doctor/draft', $this->payload([
            'specialty_id' => $internalMedicine->id,
            'subspecialty_id' => $cardiology->id,
        ]))->assertCreated();
        $cookie = $draft->getCookie('doctor_registration');
        $challenge = $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()
            ->postJson('/api/v1/auth/register/doctor/plan', ['plan' => 'basic', 'billing_cycle' => 'monthly'])
            ->assertOk();
        $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()
            ->postJson('/api/v1/auth/register/doctor/verify-otp', ['challenge_id' => $challenge->json('data.challenge_id'), 'code' => '123456'])
            ->assertOk();

        $this->assertDatabaseHas('doctor_profiles', [
            'specialty_id' => $internalMedicine->id,
            'subspecialty_id' => $cardiology->id,
            'specialty' => 'Medicina Interna',
        ]);
    }

    public function test_doctor_can_read_and_update_only_own_professional_profile(): void
    {
        $doctor = $this->authenticatedDoctor();
        $otherDoctor = User::factory()->create(['name' => 'Otra Doctora']);
        DoctorProfile::factory()->create(['user_id' => $otherDoctor->id, 'medical_license' => 'MED-OTHER']);
        $internalMedicine = Specialty::query()->where('code', 'medicina_interna')->sole();
        $cardiology = Specialty::query()->where('code', 'cardiologia')->sole();

        $this->withSpaOrigin()->getJson('/api/v1/auth/doctor/profile')
            ->assertOk()
            ->assertJsonPath('data.id', $doctor->doctorProfile->id);
        $this->withSpaOrigin()->patchJson('/api/v1/auth/doctor/profile', [
            'name' => 'Dra. Elena Actualizada',
            'medical_license' => 'MED-654321',
            'specialty_id' => $internalMedicine->id,
            'subspecialty_id' => $cardiology->id,
        ])->assertOk()->assertJsonPath('data.specialty', 'Medicina Interna');

        $this->assertDatabaseHas('users', ['id' => $doctor->id, 'name' => 'Dra. Elena Actualizada']);
        $this->assertDatabaseHas('doctor_profiles', ['user_id' => $doctor->id, 'medical_license' => 'MED-654321', 'specialty_id' => $internalMedicine->id]);
        $this->assertDatabaseHas('doctor_profiles', ['user_id' => $otherDoctor->id, 'medical_license' => 'MED-OTHER']);
    }

    public function test_specialty_only_update_preserves_the_doctor_email(): void
    {
        $doctor = $this->authenticatedDoctor();
        $specialty = Specialty::query()->where('code', 'medicina_interna')->sole();

        $this->withSpaOrigin()->patchJson('/api/v1/auth/doctor/profile', [
            'specialty_id' => $specialty->id,
        ])->assertOk()
            ->assertJsonPath('data.email', 'medico@example.test');

        $this->assertDatabaseHas('users', [
            'id' => $doctor->id,
            'email' => 'medico@example.test',
        ]);
    }

    public function test_doctor_profile_requires_an_authenticated_doctor_session(): void
    {
        $this->getJson('/api/v1/auth/doctor/profile')->assertUnauthorized();
    }

    public function test_clinic_doctor_profile_read_and_update_require_an_active_membership(): void
    {
        $context = $this->authenticatedClinicDoctor();
        $context['membership']->update(['status' => 'suspended']);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/doctor/profile')
            ->assertForbidden();
        $this->withSpaOrigin()
            ->patchJson('/api/v1/auth/doctor/profile', ['name' => 'Blocked update'])
            ->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $context['doctor']->id,
            'name' => $context['doctor']->name,
        ]);
    }

    public function test_clinic_doctor_profile_access_ends_when_the_subscription_expires(): void
    {
        $context = $this->authenticatedClinicDoctor();
        $context['subscription']->update(['ends_at' => now()->subSecond()]);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/doctor/profile')
            ->assertForbidden();
        $this->withSpaOrigin()
            ->patchJson('/api/v1/auth/doctor/profile', ['name' => 'Expired update'])
            ->assertForbidden();
    }

    private function authenticatedDoctor(): User
    {
        $specialty = Specialty::query()->where('code', 'medicina_interna')->sole();
        $draft = $this->withSpaOrigin()->postJson('/api/v1/auth/register/doctor/draft', $this->payload(['specialty_id' => $specialty->id]))->assertCreated();
        $cookie = $draft->getCookie('doctor_registration');
        $challenge = $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()
            ->postJson('/api/v1/auth/register/doctor/plan', ['plan' => 'basic', 'billing_cycle' => 'monthly'])
            ->assertOk();
        $session = $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()
            ->postJson('/api/v1/auth/register/doctor/verify-otp', ['challenge_id' => $challenge->json('data.challenge_id'), 'code' => '123456'])
            ->assertOk();
        $sessionCookie = $session->getCookie(config('session.cookie'));
        $this->withCookie($sessionCookie->getName(), $sessionCookie->getValue());

        return User::query()->where('email', 'medico@example.test')->with('doctorProfile')->sole();
    }

    /** @return array{doctor: User, membership: ClinicMembership, subscription: ClinicSubscription} */
    private function authenticatedClinicDoctor(): array
    {
        $clinic = Clinic::factory()->create();
        $subscription = ClinicSubscription::factory()->create([
            'clinic_id' => $clinic->id,
            'plan_id' => Plan::query()->sole()->id,
        ]);
        $doctor = User::factory()->create([
            'email' => 'clinic-doctor@example.test',
            'phone_number' => '+525511112222',
            'password' => 'SecurePass123',
        ]);
        $role = Role::query()->where('key', RoleKey::Doctor->value)->sole();
        $doctor->roles()->attach($role);
        DoctorProfile::factory()->create([
            'user_id' => $doctor->id,
            'clinic_id' => $clinic->id,
            'practice_type' => 'clinic',
        ]);
        $membership = ClinicMembership::factory()->create([
            'clinic_id' => $clinic->id,
            'user_id' => $doctor->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $login = $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'country_code' => 'MX',
            'phone_number' => '55 1111 2222',
            'password' => 'SecurePass123',
            'requested_role' => 'doctor',
        ])->assertAccepted();
        $verification = $this->withSpaOrigin()->postJson('/api/v1/auth/login/verify-otp', [
            'challenge_id' => $login->json('data.challenge_id'),
            'code' => '123456',
        ])->assertOk();
        $sessionCookie = $verification->getCookie(config('session.cookie'));
        $this->withCookie($sessionCookie->getName(), $sessionCookie->getValue());

        return compact('doctor', 'membership', 'subscription');
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'doctor_name' => 'Dra. Elena Ruiz',
            'doctor_email' => 'medico@example.test',
            'country_code' => 'MX',
            'doctor_phone_number' => '55 8765 4321',
            'medical_license' => 'MED-123456',
            'specialty' => null,
            'specialty_id' => null,
            'subspecialty_id' => null,
            'practice_type' => 'independent',
            'clinic_id' => null,
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
            'terms_accepted' => true,
        ];
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }
}
