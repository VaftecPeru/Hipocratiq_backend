<?php

namespace Tests\Feature\Auth;

use App\Enums\OtpDeliveryChannel;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Notifications\OtpCodeNotification;
use App\Services\Auth\OtpCodeGenerator;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class EmailOtpLoginTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
        Notification::fake();
    }

    public function test_verified_super_admin_can_login_with_email_and_receive_email_otp(): void
    {
        $user = User::factory()->superAdmin()->create([
            'email' => 'admin@example.com',
            'email_verified_at' => now(),
            'phone_verified_at' => null,
            'password' => 'SecurePass123',
        ]);

        $login = $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'identifier' => 'ADMIN@EXAMPLE.COM',
            'password' => 'SecurePass123',
            'requested_role' => 'super_admin',
        ]);

        $login
            ->assertAccepted()
            ->assertJsonPath('data.next_step', 'verify_otp');

        $challenge = OtpChallenge::query()->findOrFail($login->json('data.challenge_id'));
        $this->assertSame(OtpDeliveryChannel::Email, $challenge->delivery_channel);

        Notification::assertSentTo($user, OtpCodeNotification::class);

        $this->withSpaOrigin()->postJson('/api/v1/auth/login/verify-otp', [
            'challenge_id' => $challenge->id,
            'code' => '123456',
        ])->assertOk()->assertJsonPath('data.user.id', $user->id);

        $this->assertAuthenticatedAs($user);
    }

    public function test_unverified_email_cannot_start_email_login(): void
    {
        User::factory()->superAdmin()->create([
            'email' => 'admin@example.com',
            'email_verified_at' => null,
            'password' => 'SecurePass123',
        ]);

        $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'identifier' => 'admin@example.com',
            'password' => 'SecurePass123',
            'requested_role' => 'super_admin',
        ])->assertUnprocessable()->assertJsonValidationErrors('identifier');

        Notification::assertNothingSent();
        $this->assertDatabaseCount('otp_challenges', 0);
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }
}
