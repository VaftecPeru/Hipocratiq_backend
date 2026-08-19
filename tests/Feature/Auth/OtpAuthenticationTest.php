<?php

namespace Tests\Feature\Auth;

use App\Actions\Auth\StartLogin;
use App\Contracts\SmsSender;
use App\Enums\RoleKey;
use App\Enums\RoleScope;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\ClinicSubscription;
use App\Models\OtpChallenge;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use App\Services\Sms\TwilioSmsSender;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class OtpAuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Clinic $clinic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
        $this->clinic = Clinic::factory()->create();
    }

    public function test_patient_can_register_with_peruvian_e164_phone_without_password(): void
    {
        $request = $this->withSpaOrigin()->postJson('/api/v1/auth/otp/request', [
            'purpose' => 'registration',
            'name' => 'Ana Torres',
            'email' => 'ana@example.com',
            'phone_number' => '+51900112844',
            'clinic_id' => $this->clinic->id,
            'terms_accepted' => true,
        ]);

        $request->assertAccepted()->assertJsonPath('data.next_step', 'verify_otp');

        $user = User::query()->sole();
        $this->assertSame('+51900112844', $user->phone_number);
        $this->assertSame('PE', $user->country_code);
        $this->assertNull($user->password);

        $verification = $this->withSpaOrigin()->postJson('/api/v1/auth/otp/verify', [
            'purpose' => 'registration',
            'challenge_id' => $request->json('data.challenge_id'),
            'code' => '123456',
        ]);

        $verification
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.next_step', 'select_plan');
        $this->assertAuthenticatedAs($user);
        $this->assertNull(OtpChallenge::query()->sole()->code_hash);

        $sessionCookie = $verification->getCookie(config('session.cookie'));
        $this->withCookie($sessionCookie->getName(), $sessionCookie->getValue())
            ->withSpaOrigin()
            ->getJson('/api/v1/auth/clinic/dashboard')
            ->assertForbidden()
            ->assertJsonPath('code', 'ACTOR_CONTEXT_FORBIDDEN');
    }

    public function test_patient_can_login_on_mobile_and_revoke_device_token(): void
    {
        $user = User::factory()->create([
            'phone_number' => '+51900112844',
            'country_code' => 'PE',
            'password' => null,
        ]);
        $user->patientProfile()->create();

        $request = $this->postJson('/api/v1/auth/otp/request', [
            'purpose' => 'login',
            'identifier' => '+51900112844',
            'actor_context' => 'patient',
            'client_type' => 'mobile',
            'device_name' => 'Pixel 10',
            'device_id' => 'installation-uuid-1',
        ]);

        $request->assertAccepted();

        $verification = $this->postJson('/api/v1/auth/otp/verify', [
            'purpose' => 'login',
            'challenge_id' => $request->json('data.challenge_id'),
            'code' => '123456',
        ]);

        $verification
            ->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.id', $user->id);

        $token = $verification->json('data.access_token');
        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.client_type', 'mobile')
            ->assertJsonPath('data.actor_context', 'patient');

        $this->withToken($token)->postJson('/api/v1/auth/session/renew')
            ->assertBadRequest()
            ->assertJsonPath('code', 'WEB_SESSION_REQUIRED');

        $clinicAdminRole = Role::factory()->create([
            'key' => RoleKey::ClinicAdmin->value,
            'scope' => RoleScope::Organization,
        ]);
        ClinicMembership::factory()->create([
            'clinic_id' => $this->clinic->id,
            'user_id' => $user->id,
            'role_id' => $clinicAdminRole->id,
        ]);
        ClinicSubscription::factory()->create(['clinic_id' => $this->clinic->id]);

        $this->withToken($token)->getJson('/api/v1/auth/clinic/dashboard')
            ->assertForbidden()
            ->assertJsonPath('code', 'ACTOR_CONTEXT_FORBIDDEN');

        $legacyPrivilegedToken = $user->createToken('legacy-privileged', [
            RoleKey::ClinicAdmin->value,
        ])->plainTextToken;
        $this->withToken($legacyPrivilegedToken)->getJson('/api/v1/auth/clinic/dashboard')
            ->assertForbidden()
            ->assertJsonPath('code', 'ACTOR_CONTEXT_FORBIDDEN');
        $user->tokens()->where('name', 'legacy-privileged')->delete();

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_staff_login_requires_password_before_issuing_otp(): void
    {
        User::factory()->superAdmin()->create([
            'email' => 'doctor@example.com',
            'password' => 'SecurePass123',
        ]);

        $this->withSpaOrigin()->postJson('/api/v1/auth/otp/request', [
            'purpose' => 'login',
            'identifier' => 'doctor@example.com',
            'actor_context' => 'staff',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->withSpaOrigin()->postJson('/api/v1/auth/otp/request', [
            'purpose' => 'login',
            'identifier' => 'doctor@example.com',
            'actor_context' => 'staff',
            'password' => 'WrongPassword123',
        ])->assertUnprocessable()->assertJsonValidationErrors('identifier');

        $this->withSpaOrigin()->postJson('/api/v1/auth/otp/request', [
            'purpose' => 'login',
            'identifier' => 'doctor@example.com',
            'actor_context' => 'staff',
            'password' => 'SecurePass123',
        ])->assertAccepted();
    }

    public function test_staff_can_login_on_mobile_and_receive_actor_context(): void
    {
        $clinicAdminRole = Role::factory()->create([
            'key' => RoleKey::ClinicAdmin->value,
            'scope' => RoleScope::Organization,
        ]);
        $user = User::factory()->create([
            'email' => 'mobile-staff@example.com',
            'password' => 'SecurePass123',
        ]);
        ClinicMembership::factory()->create([
            'clinic_id' => $this->clinic->id,
            'user_id' => $user->id,
            'role_id' => $clinicAdminRole->id,
        ]);
        ClinicSubscription::factory()->create(['clinic_id' => $this->clinic->id]);

        $request = $this->postJson('/api/v1/auth/otp/request', [
            'purpose' => 'login',
            'identifier' => 'mobile-staff@example.com',
            'actor_context' => 'staff',
            'client_type' => 'mobile',
            'password' => 'SecurePass123',
            'device_name' => 'Pixel 10',
            'device_id' => 'installation-staff-1',
        ]);

        $request->assertAccepted();

        $verification = $this->postJson('/api/v1/auth/otp/verify', [
            'purpose' => 'login',
            'challenge_id' => $request->json('data.challenge_id'),
            'code' => '123456',
        ]);

        $verification
            ->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.actor_context', 'clinic_admin');

        $this->withToken($verification->json('data.access_token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.client_type', 'mobile')
            ->assertJsonPath('data.actor_context', 'clinic_admin');
    }

    public function test_client_cannot_select_a_privileged_actor_context(): void
    {
        foreach (['clinic_admin', 'doctor'] as $actorContext) {
            $this->postJson('/api/v1/auth/otp/request', [
                'purpose' => 'login',
                'identifier' => 'staff@example.com',
                'actor_context' => $actorContext,
                'client_type' => 'mobile',
                'device_name' => 'Untrusted device',
                'device_id' => 'untrusted-installation',
            ])->assertUnprocessable()->assertJsonValidationErrors('actor_context');
        }

        $this->assertDatabaseCount('otp_challenges', 0);
    }

    public function test_start_login_action_rejects_internal_actor_contexts(): void
    {
        User::factory()->superAdmin()->create([
            'email' => 'staff@example.com',
            'password' => 'SecurePass123',
        ]);
        $startLogin = $this->app->make(StartLogin::class);

        foreach (['clinic_admin', 'doctor'] as $actorContext) {
            try {
                $startLogin->handle([
                    'purpose' => 'login',
                    'identifier' => 'staff@example.com',
                    'actor_context' => $actorContext,
                    'client_type' => 'mobile',
                    'device_name' => 'Untrusted device',
                    'device_id' => 'untrusted-installation',
                ]);
                $this->fail("The {$actorContext} context was accepted.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('identifier', $exception->errors());
            }
        }

        $this->assertDatabaseCount('otp_challenges', 0);
        $this->assertDatabaseCount('authentication_events', 2);
    }

    public function test_staff_can_reset_password_with_otp_and_existing_tokens_are_revoked(): void
    {
        $user = User::factory()->superAdmin()->create([
            'email' => 'doctor@example.com',
            'phone_number' => '+51900112844',
            'country_code' => 'PE',
            'password' => 'SecurePass123',
        ]);
        $user->createToken('old-device');

        $request = $this->postJson('/api/v1/auth/otp/request', [
            'purpose' => 'password_reset',
            'identifier' => 'doctor@example.com',
        ]);

        $request->assertAccepted();

        $this->postJson('/api/v1/auth/otp/verify', [
            'purpose' => 'password_reset',
            'challenge_id' => $request->json('data.challenge_id'),
            'code' => '123456',
            'password' => 'NewSecure456',
            'password_confirmation' => 'NewSecure456',
        ])->assertOk()->assertJsonPath('data.next_step', 'login');

        $this->assertTrue(Hash::check('NewSecure456', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNull(OtpChallenge::query()->sole()->code_hash);
        $this->assertDatabaseHas('authentication_events', [
            'user_id' => $user->id,
            'event' => 'password_reset',
            'outcome' => 'success',
        ]);
    }

    public function test_non_admin_user_can_reset_password_with_otp(): void
    {
        User::factory()->create([
            'email' => 'clinicuser@example.com',
            'phone_number' => '+51900223355',
            'country_code' => 'PE',
            'password' => 'OldPassword1!',
        ]);

        $request = $this->postJson('/api/v1/auth/otp/request', [
            'purpose' => 'password_reset',
            'identifier' => 'clinicuser@example.com',
        ]);

        $request->assertAccepted();

        $this->postJson('/api/v1/auth/otp/verify', [
            'purpose' => 'password_reset',
            'challenge_id' => $request->json('data.challenge_id'),
            'code' => '123456',
            'password' => 'NewPassword2!',
            'password_confirmation' => 'NewPassword2!',
        ])->assertOk()->assertJsonPath('data.next_step', 'login');

        $this->assertTrue(Hash::check('NewPassword2!', User::query()->where('email', 'clinicuser@example.com')->sole()->password));
    }

    public function test_twilio_sender_uses_messaging_service_without_exposing_credentials(): void
    {
        config()->set('sms.twilio', [
            'account_sid' => 'AC123',
            'auth_token' => 'secret-token',
            'messaging_service_sid' => 'MG123',
        ]);
        Http::fake([
            'api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201),
        ]);

        (new TwilioSmsSender)->send('+51900112844', 'Tu código es 123456.');

        Http::assertSent(fn (Request $request): bool => $request->url()
            === 'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages.json'
            && $request['MessagingServiceSid'] === 'MG123'
            && $request['To'] === '+51900112844');
    }

    public function test_delivery_failure_rolls_back_registration_and_returns_service_unavailable(): void
    {
        Log::spy();
        $this->app->instance(SmsSender::class, new class implements SmsSender
        {
            public function send(string $phoneNumber, string $message): void
            {
                throw new RuntimeException('Provider unavailable.');
            }
        });

        $response = $this->postJson('/api/v1/auth/otp/request', [
            'purpose' => 'registration',
            'name' => 'Ana Torres',
            'phone_number' => '+51900112844',
            'clinic_id' => $this->clinic->id,
            'terms_accepted' => true,
        ]);

        $response
            ->assertServiceUnavailable()
            ->assertJson([
                'success' => false,
                'code' => 'OTP_DELIVERY_UNAVAILABLE',
                'message' => 'No se pudo enviar el código OTP.',
            ]);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('patient_profiles', 0);
        $this->assertDatabaseCount('otp_challenges', 0);
        $this->assertDatabaseMissing('authentication_events', [
            'event' => 'otp_sent',
        ]);
        Log::shouldHaveReceived('error')->once();
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }
}
