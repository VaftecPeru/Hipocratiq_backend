<?php

namespace Tests\Feature\Auth;

use App\Enums\OnboardingStatus;
use App\Enums\UserStatus;
use App\Models\AuthenticationEvent;
use App\Models\AuthenticationSession;
use App\Models\Clinic;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Services\Auth\AuthenticationSessionKey;
use App\Services\Auth\OtpCodeGenerator;
use App\Services\Sms\ArraySmsSender;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class PatientRegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Clinic $clinic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
        $this->app->make(ArraySmsSender::class)->clear();
        $this->clinic = Clinic::factory()->create(['name' => 'Clínica para pacientes']);
    }

    public function test_patient_can_register_verify_phone_and_start_session(): void
    {
        $response = $this->withSpaOrigin()->postJson('/api/v1/auth/register/patient', $this->payload());

        $response
            ->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.next_step', 'verify_phone')
            ->assertJsonStructure(['data' => ['challenge_id', 'expires_in', 'resend_after']]);

        $user = User::query()->sole();

        $this->assertSame('+525512345678', $user->phone_number);
        $this->assertSame(UserStatus::PendingVerification, $user->status);
        $this->assertTrue(Hash::check('SecurePass123', $user->password));
        $this->assertModelExists($user->patientProfile);
        $this->assertSame($this->clinic->id, $user->patientProfile->clinic_id);
        $messages = $this->app->make(ArraySmsSender::class)->messages();

        $this->assertCount(1, $messages);
        $this->assertSame(
            'Tu código Hipocratiq es 123456. Expira en 5 minutos.',
            $messages[0]['message'],
        );

        $verifyResponse = $this->withSpaOrigin()->postJson('/api/v1/auth/register/verify-otp', [
            'challenge_id' => $response->json('data.challenge_id'),
            'code' => '123456',
        ]);

        $verifyResponse
            ->assertOk()
            ->assertJsonPath('data.next_step', 'select_plan')
            ->assertJsonPath('data.user.phone_number', '+525512345678')
            ->assertJsonPath('data.user.status', UserStatus::Active->value)
            ->assertJsonPath('data.user.onboarding_status', OnboardingStatus::PlanSelection->value);

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->phone_verified_at);
        $this->assertSame(1, AuthenticationSession::query()->count());

        $sessionCookie = $verifyResponse->getCookie(config('session.cookie'));
        $this->assertSame(
            AuthenticationSession::query()->sole()->session_id_hash,
            $this->app->make(AuthenticationSessionKey::class)->make($sessionCookie->getValue()),
        );
        $this->withCookie($sessionCookie->getName(), $sessionCookie->getValue());

        $me = $this->withSpaOrigin()->getJson('/api/v1/auth/me');

        $this->assertSame(200, $me->status(), $me->getContent());
        $me->assertJsonPath('data.user.id', $user->id);
    }

    public function test_registration_rejects_duplicate_normalized_phone_number(): void
    {
        User::factory()->create(['phone_number' => '+525512345678']);

        $this->withSpaOrigin()->postJson('/api/v1/auth/register/patient', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone_number');
    }

    public function test_registration_rejects_invalid_phone_number(): void
    {
        $this->withSpaOrigin()->postJson('/api/v1/auth/register/patient', [
            ...$this->payload(),
            'phone_number' => '123',
        ])->assertUnprocessable()->assertJsonValidationErrors('phone_number');
    }

    public function test_registration_requires_an_active_registered_clinic(): void
    {
        $this->withSpaOrigin()->postJson('/api/v1/auth/register/patient', [
            ...$this->payload(),
            'clinic_id' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors('clinic_id');

        $inactiveClinic = Clinic::factory()->create(['status' => 'suspended']);
        $this->withSpaOrigin()->postJson('/api/v1/auth/register/patient', [
            ...$this->payload(),
            'clinic_id' => $inactiveClinic->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('clinic_id');
    }

    public function test_wrong_otp_increments_attempts_without_starting_session(): void
    {
        $registration = $this->withSpaOrigin()->postJson('/api/v1/auth/register/patient', $this->payload());

        $this->withSpaOrigin()->postJson('/api/v1/auth/register/verify-otp', [
            'challenge_id' => $registration->json('data.challenge_id'),
            'code' => '000000',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->assertGuest();
        $this->assertSame(1, OtpChallenge::query()->sole()->attempts);
    }

    public function test_patient_can_resend_otp_after_cooldown(): void
    {
        $registration = $this->withSpaOrigin()->postJson('/api/v1/auth/register/patient', $this->payload());
        $firstChallengeId = $registration->json('data.challenge_id');

        $this->withSpaOrigin()->postJson('/api/v1/auth/otp/resend', [
            'challenge_id' => $firstChallengeId,
        ])->assertUnprocessable()->assertJsonValidationErrors('challenge_id');

        $this->travel(61)->seconds();

        $resend = $this->withSpaOrigin()->postJson('/api/v1/auth/otp/resend', [
            'challenge_id' => $firstChallengeId,
        ]);

        $resend->assertAccepted()->assertJsonPath('data.next_step', 'verify_otp');
        $this->assertNotSame($firstChallengeId, $resend->json('data.challenge_id'));
        $this->assertNotNull(OtpChallenge::query()->findOrFail($firstChallengeId)->invalidated_at);
        $this->assertCount(2, $this->app->make(ArraySmsSender::class)->messages());
    }

    public function test_patient_otp_expires_at_exactly_five_minutes(): void
    {
        $registration = $this->withSpaOrigin()->postJson('/api/v1/auth/register/patient', $this->payload());
        $this->travel(5)->minutes();

        $this->withSpaOrigin()->postJson('/api/v1/auth/register/verify-otp', [
            'challenge_id' => $registration->json('data.challenge_id'),
            'code' => '123456',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->assertGuest();
        $this->assertSame(UserStatus::PendingVerification, User::query()->sole()->status);
    }

    public function test_patient_allows_only_five_otp_resends(): void
    {
        $registration = $this->withSpaOrigin()->postJson('/api/v1/auth/register/patient', $this->payload());
        $challengeId = $registration->json('data.challenge_id');

        for ($resend = 1; $resend <= 5; $resend++) {
            $this->travel(61)->seconds();
            $response = $this->withSpaOrigin()->postJson('/api/v1/auth/otp/resend', [
                'challenge_id' => $challengeId,
            ]);
            $response->assertAccepted()->assertJsonPath('data.resends_remaining', 5 - $resend);
            $challengeId = $response->json('data.challenge_id');
        }

        $this->travel(61)->seconds();
        $this->withSpaOrigin()->postJson('/api/v1/auth/otp/resend', [
            'challenge_id' => $challengeId,
        ])->assertUnprocessable()->assertJsonValidationErrors('challenge_id');
        $this->assertCount(6, $this->app->make(ArraySmsSender::class)->messages());
    }

    public function test_registration_limits_password_length(): void
    {
        $password = str_repeat('Aa1', 25);

        $this->withSpaOrigin()->postJson('/api/v1/auth/register/patient', [
            ...$this->payload(),
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_invalid_request_id_does_not_break_registration(): void
    {
        $this->withHeader('X-Request-ID', str_repeat('a', 500));

        $this->withSpaOrigin()->postJson('/api/v1/auth/register/patient', $this->payload())
            ->assertAccepted();

        $this->assertNull(AuthenticationEvent::query()->firstOrFail()->request_id);
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }

    private function payload(): array
    {
        return [
            'name' => 'Ana Martínez',
            'email' => 'ana@example.com',
            'country_code' => 'MX',
            'clinic_id' => $this->clinic->id,
            'phone_number' => '55 1234 5678',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
            'terms_accepted' => true,
        ];
    }
}
