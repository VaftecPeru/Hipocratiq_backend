<?php

namespace Tests\Feature\Auth;

use App\Contracts\PhoneOtpProvider;
use App\Models\Clinic;
use App\Models\OtpChallenge;
use App\Services\Auth\PhoneOtpDelivery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AwsNotifyOtpFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_phone_otp_can_be_issued_and_verified_by_external_provider_without_storing_the_code(): void
    {
        $provider = new class implements PhoneOtpProvider
        {
            public array $issued = [];

            public array $verified = [];

            public function key(): string
            {
                return 'aws_notify';
            }

            public function issue(string $phoneNumber, string $referenceId): PhoneOtpDelivery
            {
                $this->issued[] = [$phoneNumber, $referenceId];

                return new PhoneOtpDelivery(
                    provider: $this->key(),
                    messageId: 'message-123',
                    verificationId: 'verification-123',
                );
            }

            public function verify(
                string $phoneNumber,
                string $referenceId,
                string $code,
                ?string $codeHash = null,
            ): bool {
                $this->verified[] = [$phoneNumber, $referenceId, $code, $codeHash];

                return $code === '654321';
            }
        };

        $this->app->instance(PhoneOtpProvider::class, $provider);

        $clinic = Clinic::factory()->create();

        $request = $this->postJson('/api/v1/auth/otp/request', [
            'purpose' => 'registration',
            'name' => 'Paciente AWS',
            'phone_number' => '+51900112844',
            'clinic_id' => $clinic->id,
            'terms_accepted' => true,
        ]);

        $request->assertAccepted();

        $challenge = OtpChallenge::query()->findOrFail($request->json('data.challenge_id'));

        $this->assertSame('aws_notify', $challenge->verification_provider);
        $this->assertSame('message-123', $challenge->provider_message_id);
        $this->assertSame('verification-123', $challenge->provider_verification_id);
        $this->assertNull($challenge->code_hash);
        $this->assertSame([['+51900112844', $challenge->id]], $provider->issued);

        $verification = $this->postJson('/api/v1/auth/otp/verify', [
            'purpose' => 'registration',
            'challenge_id' => $challenge->id,
            'code' => '654321',
        ]);

        $verification->assertOk()->assertJsonPath('data.user.phone_number', '+51900112844');

        $challenge->refresh();

        $this->assertNotNull($challenge->consumed_at);
        $this->assertNull($challenge->code_hash);
        $this->assertSame(
            [['+51900112844', $challenge->id, '654321', null]],
            $provider->verified,
        );
    }

    public function test_external_phone_provider_invalid_code_keeps_error_generic_and_tracks_attempt(): void
    {
        $provider = new class implements PhoneOtpProvider
        {
            public function key(): string
            {
                return 'aws_notify';
            }

            public function issue(string $phoneNumber, string $referenceId): PhoneOtpDelivery
            {
                return new PhoneOtpDelivery(provider: $this->key());
            }

            public function verify(
                string $phoneNumber,
                string $referenceId,
                string $code,
                ?string $codeHash = null,
            ): bool {
                return false;
            }
        };

        $this->app->instance(PhoneOtpProvider::class, $provider);

        $clinic = Clinic::factory()->create();

        $request = $this->postJson('/api/v1/auth/otp/request', [
            'purpose' => 'registration',
            'name' => 'Paciente AWS',
            'phone_number' => '+51900112844',
            'clinic_id' => $clinic->id,
            'terms_accepted' => true,
        ]);

        $challengeId = $request->json('data.challenge_id');

        $this->postJson('/api/v1/auth/otp/verify', [
            'purpose' => 'registration',
            'challenge_id' => $challengeId,
            'code' => '111111',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('code')
            ->assertJsonPath('errors.code.0', 'El código no es válido.');

        $this->assertDatabaseHas('otp_challenges', [
            'id' => $challengeId,
            'attempts' => 1,
        ]);
    }
}
