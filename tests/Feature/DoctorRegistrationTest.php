<?php

namespace Tests\Feature;

use App\Enums\RoleKey;
use App\Enums\RoleScope;
use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\PersonalAccessToken;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use App\Services\Sms\ArraySmsSender;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class DoctorRegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
        $this->app->make(ArraySmsSender::class)->clear();
        Role::query()->create(['key' => RoleKey::Doctor->value, 'name' => 'Médico', 'scope' => RoleScope::Organization, 'is_system' => true]);
        $this->plan = Plan::query()->create(['key' => 'basic', 'name' => 'Básico', 'monthly_price_cents' => 4900, 'annual_price_cents' => 47000, 'currency' => 'USD', 'features' => [], 'publicly_selectable' => true, 'is_active' => true]);
    }

    public function test_clinic_doctor_uses_clinic_plan_and_registers_with_otp(): void
    {
        $clinic = Clinic::factory()->create();
        ClinicSubscription::factory()->create(['clinic_id' => $clinic->id, 'plan_id' => $this->plan->id]);

        $draft = $this->withSpaOrigin()->postJson('/api/v1/auth/register/doctor/draft', $this->payload([
            'practice_type' => 'clinic',
            'clinic_id' => $clinic->id,
        ]));

        $draft->assertCreated()->assertJsonPath('data.next_step', 'verify_otp');
        $this->assertDatabaseCount('users', 0);
        $cookie = $draft->getCookie('doctor_registration');

        $verify = $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()->postJson('/api/v1/auth/register/doctor/verify-otp', [
            'challenge_id' => $draft->json('data.challenge_id'),
            'code' => '123456',
        ]);

        $verify->assertOk()->assertJsonPath('data.next_step', 'success');
        $this->assertDatabaseHas('doctor_profiles', ['clinic_id' => $clinic->id, 'practice_type' => 'clinic']);
        $this->assertDatabaseHas('clinic_memberships', ['clinic_id' => $clinic->id, 'status' => 'active']);
        $this->assertDatabaseCount('doctor_subscriptions', 0);
        $this->assertAuthenticated();
    }

    public function test_independent_doctor_can_register_on_mobile_with_a_device_bound_draft(): void
    {
        $deviceName = 'Doctor phone';
        $deviceId = 'doctor-device-0001';
        $draft = $this->postJson('/api/v1/auth/register/doctor/draft', $this->payload([
            'client_type' => 'mobile',
            'device_name' => $deviceName,
            'device_id' => $deviceId,
        ]));

        $draft
            ->assertCreated()
            ->assertJsonPath('data.next_step', 'select_plan')
            ->assertJsonStructure(['data' => ['draft_token']]);
        $draftToken = $draft->json('data.draft_token');

        $this->withHeaders($this->mobileDraftHeaders($draftToken, $deviceName, 'different-device-0001'))
            ->postJson('/api/v1/auth/register/doctor/plan', [
                'plan' => 'basic',
                'billing_cycle' => 'monthly',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('registration');

        $this->withHeaders($this->mobileDraftHeaders($draftToken, $deviceName, $deviceId))
            ->postJson('/api/v1/auth/register/clinic/plan', [
                'plan' => 'basic',
                'billing_cycle' => 'monthly',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('registration');

        $plan = $this->withHeaders($this->mobileDraftHeaders($draftToken, $deviceName, $deviceId))
            ->postJson('/api/v1/auth/register/doctor/plan', [
                'plan' => 'basic',
                'billing_cycle' => 'monthly',
            ])
            ->assertOk()
            ->assertJsonPath('data.next_step', 'verify_otp');

        $verification = $this->withHeaders($this->mobileDraftHeaders($draftToken, $deviceName, $deviceId))
            ->postJson('/api/v1/auth/register/doctor/verify-otp', [
                'challenge_id' => $plan->json('data.challenge_id'),
                'code' => '123456',
            ])
            ->assertOk();

        $verification
            ->assertJsonPath('data.actor_context', 'doctor')
            ->assertJsonPath('data.next_step', 'success');
        $token = PersonalAccessToken::query()->where('tokenable_id', User::query()->sole()->id)->sole();
        $this->assertSame('mobile', $token->client_type->value);
        $this->assertSame('doctor', $token->actor_context->value);
        $this->assertSame(hash('sha256', $deviceId), $token->device_id_hash);
    }

    public function test_clinic_without_active_plan_is_rejected_before_otp(): void
    {
        $clinic = Clinic::factory()->create();

        $this->withSpaOrigin()->postJson('/api/v1/auth/register/doctor/draft', $this->payload([
            'practice_type' => 'clinic',
            'clinic_id' => $clinic->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('clinic_id');

        $this->assertDatabaseCount('users', 0);
        $this->assertSame([], $this->app->make(ArraySmsSender::class)->messages());
    }

    public function test_doctor_registration_excludes_future_clinic_subscriptions(): void
    {
        $clinic = Clinic::factory()->create();
        ClinicSubscription::factory()->create([
            'clinic_id' => $clinic->id,
            'plan_id' => $this->plan->id,
            'starts_at' => now()->addDay(),
        ]);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/register/doctor/clinics')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withSpaOrigin()->postJson('/api/v1/auth/register/doctor/draft', $this->payload([
            'practice_type' => 'clinic',
            'clinic_id' => $clinic->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('clinic_id');
    }

    public function test_doctor_registration_completion_rechecks_clinic_subscription_start_time(): void
    {
        $clinic = Clinic::factory()->create();
        $subscription = ClinicSubscription::factory()->create([
            'clinic_id' => $clinic->id,
            'plan_id' => $this->plan->id,
        ]);
        $draft = $this->withSpaOrigin()->postJson('/api/v1/auth/register/doctor/draft', $this->payload([
            'practice_type' => 'clinic',
            'clinic_id' => $clinic->id,
        ]))->assertCreated();
        $cookie = $draft->getCookie('doctor_registration');
        $subscription->update(['starts_at' => now()->addDay()]);

        $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()
            ->postJson('/api/v1/auth/register/doctor/verify-otp', [
                'challenge_id' => $draft->json('data.challenge_id'),
                'code' => '123456',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('clinic_id');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('doctor_profiles', 0);
    }

    public function test_independent_doctor_must_select_plan_before_otp(): void
    {
        $draft = $this->withSpaOrigin()->postJson('/api/v1/auth/register/doctor/draft', $this->payload());
        $draft->assertCreated()->assertJsonPath('data.next_step', 'select_plan');
        $cookie = $draft->getCookie('doctor_registration');

        $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()->postJson('/api/v1/auth/register/doctor/resend-otp')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan');

        $plan = $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()->postJson('/api/v1/auth/register/doctor/plan', [
            'plan' => 'basic',
            'billing_cycle' => 'monthly',
        ]);
        $plan->assertOk()->assertJsonPath('data.next_step', 'verify_otp');

        $verify = $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()->postJson('/api/v1/auth/register/doctor/verify-otp', [
            'challenge_id' => $plan->json('data.challenge_id'),
            'code' => '123456',
        ]);

        $verify->assertOk();
        $this->assertDatabaseHas('doctor_profiles', ['clinic_id' => null, 'practice_type' => 'independent']);
        $this->assertDatabaseHas('doctor_subscriptions', ['plan_id' => $this->plan->id, 'status' => 'active']);
        $this->assertDatabaseCount('clinic_memberships', 0);
    }

    public function test_registered_doctor_can_login_with_password_and_otp(): void
    {
        $draft = $this->withSpaOrigin()->postJson('/api/v1/auth/register/doctor/draft', $this->payload());
        $cookie = $draft->getCookie('doctor_registration');
        $plan = $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()->postJson('/api/v1/auth/register/doctor/plan', ['plan' => 'basic', 'billing_cycle' => 'monthly']);
        $verification = $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()->postJson('/api/v1/auth/register/doctor/verify-otp', ['challenge_id' => $plan->json('data.challenge_id'), 'code' => '123456']);
        $sessionCookie = $verification->getCookie(config('session.cookie'));
        $this->withCookie($sessionCookie->getName(), $sessionCookie->getValue())->withSpaOrigin()->postJson('/api/v1/auth/logout')->assertNoContent();

        $login = $this->withSpaOrigin()->postJson('/api/v1/auth/otp/request', [
            'purpose' => 'login',
            'client_type' => 'web',
            'actor_context' => 'staff',
            'requested_role' => 'doctor',
            'identifier' => 'medico@example.test',
            'password' => 'SecurePass123',
        ]);

        $login->assertAccepted();
        $this->withSpaOrigin()->postJson('/api/v1/auth/otp/verify', [
            'purpose' => 'login',
            'challenge_id' => $login->json('data.challenge_id'),
            'code' => '123456',
        ])->assertOk()->assertJsonPath('data.actor_context', 'doctor');
    }

    public function test_doctor_cannot_bypass_password_using_patient_context(): void
    {
        $draft = $this->withSpaOrigin()->postJson('/api/v1/auth/register/doctor/draft', $this->payload());
        $cookie = $draft->getCookie('doctor_registration');
        $plan = $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()->postJson('/api/v1/auth/register/doctor/plan', ['plan' => 'basic', 'billing_cycle' => 'monthly']);
        $verification = $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()->postJson('/api/v1/auth/register/doctor/verify-otp', [
            'challenge_id' => $plan->json('data.challenge_id'),
            'code' => '123456',
        ]);
        $sessionCookie = $verification->getCookie(config('session.cookie'));
        $this->withCookie($sessionCookie->getName(), $sessionCookie->getValue())->withSpaOrigin()->postJson('/api/v1/auth/logout')->assertNoContent();

        $user = User::query()->sole();
        $user->patientProfile()->create();

        $this->postJson('/api/v1/auth/otp/request', [
            'purpose' => 'login',
            'identifier' => 'medico@example.test',
            'actor_context' => 'patient',
            'client_type' => 'mobile',
            'requested_role' => 'doctor',
            'device_name' => 'Untrusted device',
            'device_id' => 'untrusted-installation',
        ])->assertUnprocessable()->assertJsonValidationErrors('identifier');

        $this->assertDatabaseCount('otp_challenges', 0);
    }

    public function test_wrong_otp_does_not_create_doctor(): void
    {
        $draft = $this->withSpaOrigin()->postJson('/api/v1/auth/register/doctor/draft', $this->payload());
        $cookie = $draft->getCookie('doctor_registration');
        $plan = $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()->postJson('/api/v1/auth/register/doctor/plan', ['plan' => 'basic', 'billing_cycle' => 'annual']);

        $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()->postJson('/api/v1/auth/register/doctor/verify-otp', [
            'challenge_id' => $plan->json('data.challenge_id'),
            'code' => '000000',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('doctor_profiles', 0);
        $this->assertDatabaseCount('doctor_subscriptions', 0);
    }

    public function test_doctor_otp_expires_at_exactly_five_minutes(): void
    {
        $draft = $this->withSpaOrigin()->postJson('/api/v1/auth/register/doctor/draft', $this->payload());
        $cookie = $draft->getCookie('doctor_registration');
        $plan = $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()->postJson('/api/v1/auth/register/doctor/plan', [
            'plan' => 'basic',
            'billing_cycle' => 'monthly',
        ]);

        $this->travel(5)->minutes();
        $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()->postJson('/api/v1/auth/register/doctor/verify-otp', [
            'challenge_id' => $plan->json('data.challenge_id'),
            'code' => '123456',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('doctor_profiles', 0);
    }

    public function test_doctor_registration_allows_only_five_otp_resends(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $draft = $this->withSpaOrigin()->postJson('/api/v1/auth/register/doctor/draft', $this->payload());
        $cookie = $draft->getCookie('doctor_registration');
        $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()->postJson('/api/v1/auth/register/doctor/plan', [
            'plan' => 'basic',
            'billing_cycle' => 'monthly',
        ])->assertJsonPath('data.resends_remaining', 5);

        for ($resend = 1; $resend <= 5; $resend++) {
            $this->travel(61)->seconds();
            $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()
                ->postJson('/api/v1/auth/register/doctor/resend-otp')
                ->assertOk()
                ->assertJsonPath('data.resends_remaining', 5 - $resend);
        }

        $this->travel(61)->seconds();
        $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()
            ->postJson('/api/v1/auth/register/doctor/resend-otp')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('otp');
        $this->assertCount(6, $this->app->make(ArraySmsSender::class)->messages());
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'doctor_name' => 'Dra. Elena Ruiz',
            'doctor_email' => 'medico@example.test',
            'country_code' => 'MX',
            'doctor_phone_number' => '55 8765 4321',
            'medical_license' => '12345678',
            'specialty' => 'Cardiología',
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

    /** @return array<string, string> */
    private function mobileDraftHeaders(string $draftToken, string $deviceName, string $deviceId): array
    {
        return [
            'X-Client-Type' => 'mobile',
            'X-Device-Name' => $deviceName,
            'X-Device-ID' => $deviceId,
            'X-Registration-Token' => $draftToken,
        ];
    }
}
