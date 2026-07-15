<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\AuthenticationEvent;
use App\Models\AuthenticationSession;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
    }

    public function test_verified_user_can_login_with_password_and_otp_then_logout(): void
    {
        $user = User::factory()->superAdmin()->create([
            'phone_number' => '+525512345678',
            'password' => 'SecurePass123',
        ]);

        $login = $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'country_code' => 'MX',
            'phone_number' => '55 1234 5678',
            'password' => 'SecurePass123',
        ]);

        $login
            ->assertAccepted()
            ->assertJsonPath('data.next_step', 'verify_otp');
        $this->assertGuest();

        $verification = $this->withSpaOrigin()->postJson('/api/v1/auth/login/verify-otp', [
            'challenge_id' => $login->json('data.challenge_id'),
            'code' => '123456',
        ]);

        $verification->assertOk()->assertJsonPath('data.user.id', $user->id);

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, AuthenticationSession::query()->count());

        $sessionCookie = $verification->getCookie(config('session.cookie'));
        $this->withCookie($sessionCookie->getName(), $sessionCookie->getValue());

        $logout = $this->withSpaOrigin()->postJson('/api/v1/auth/logout');

        $this->assertSame(204, $logout->status(), $logout->getContent());

        $this->withSpaOrigin()->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertNotNull(AuthenticationSession::query()->sole()->revoked_at);
        $this->assertDatabaseHas('authentication_events', [
            'user_id' => $user->id,
            'event' => 'logout',
            'outcome' => 'success',
        ]);
    }

    public function test_invalid_password_does_not_issue_otp_challenge(): void
    {
        User::factory()->superAdmin()->create([
            'phone_number' => '+525512345678',
            'password' => 'SecurePass123',
        ]);

        $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'country_code' => 'MX',
            'phone_number' => '55 1234 5678',
            'password' => 'WrongPassword123',
        ])->assertUnprocessable()->assertJsonValidationErrors('phone_number');

        $this->assertGuest();
        $this->assertDatabaseCount('otp_challenges', 0);
        $this->assertSame('login_failed', AuthenticationEvent::query()->sole()->event);
    }

    public function test_unverified_user_cannot_start_login(): void
    {
        User::factory()->superAdmin()->unverified()->create([
            'phone_number' => '+525512345678',
            'password' => 'SecurePass123',
        ]);

        $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'country_code' => 'MX',
            'phone_number' => '55 1234 5678',
            'password' => 'SecurePass123',
        ])->assertUnprocessable()->assertJsonValidationErrors('phone_number');

        $this->assertGuest();
    }

    public function test_protected_route_rejects_guest(): void
    {
        $this->withSpaOrigin()->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_suspended_user_cannot_finish_pending_login(): void
    {
        $user = User::factory()->superAdmin()->create([
            'phone_number' => '+525512345678',
            'password' => 'SecurePass123',
        ]);

        $login = $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'country_code' => 'MX',
            'phone_number' => '55 1234 5678',
            'password' => 'SecurePass123',
        ]);

        $user->update(['status' => UserStatus::Suspended]);

        $this->withSpaOrigin()->postJson('/api/v1/auth/login/verify-otp', [
            'challenge_id' => $login->json('data.challenge_id'),
            'code' => '123456',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->assertGuest();
        $this->assertDatabaseCount('authentication_sessions', 0);
    }

    public function test_expired_authentication_session_is_rejected_and_audited(): void
    {
        [$user, $cookieName, $cookieValue] = $this->authenticatedUser();
        AuthenticationSession::query()->update(['expires_at' => now()->subSecond()]);

        $response = $this->withCookie($cookieName, $cookieValue)
            ->withSpaOrigin()
            ->getJson('/api/v1/auth/me');

        $response->assertUnauthorized()->assertJsonPath('code', 'SESSION_EXPIRED');
        $this->assertDatabaseHas('authentication_events', [
            'user_id' => $user->id,
            'event' => 'session_expired',
        ]);
    }

    public function test_suspended_user_with_active_session_is_rejected(): void
    {
        [$user, $cookieName, $cookieValue] = $this->authenticatedUser();
        $user->update(['status' => UserStatus::Suspended]);

        $response = $this->withCookie($cookieName, $cookieValue)
            ->withSpaOrigin()
            ->getJson('/api/v1/auth/me');

        $response->assertForbidden()->assertJsonPath('code', 'ACCOUNT_INACTIVE');
        $this->assertSame('account_inactive', AuthenticationSession::query()->sole()->revoked_reason);
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }

    private function authenticatedUser(): array
    {
        $user = User::factory()->superAdmin()->create([
            'phone_number' => '+525512345678',
            'password' => 'SecurePass123',
        ]);

        $login = $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'country_code' => 'MX',
            'phone_number' => '55 1234 5678',
            'password' => 'SecurePass123',
        ]);
        $verification = $this->withSpaOrigin()->postJson('/api/v1/auth/login/verify-otp', [
            'challenge_id' => $login->json('data.challenge_id'),
            'code' => '123456',
        ]);
        $cookie = $verification->getCookie(config('session.cookie'));

        return [$user, $cookie->getName(), $cookie->getValue()];
    }
}
