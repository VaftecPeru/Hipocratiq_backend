<?php

namespace App\Actions\Auth;

use App\Contracts\SmsSender;
use App\Enums\AuthenticationClient;
use App\Enums\AuthenticationContext;
use App\Enums\OtpPurpose;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use App\Services\Auth\OtpHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

final class IssueOtpChallenge
{
    public function __construct(
        private OtpCodeGenerator $codeGenerator,
        private OtpHasher $hasher,
        private SmsSender $smsSender,
        private RecordAuthenticationEvent $recordEvent,
    ) {}

    public function handle(
        User $user,
        OtpPurpose $purpose,
        AuthenticationClient $client = AuthenticationClient::Web,
        AuthenticationContext $context = AuthenticationContext::Staff,
        ?string $deviceName = null,
        ?string $deviceId = null,
        ?string $deviceIdHash = null,
    ): OtpChallenge {
        return DB::transaction(function () use ($user, $purpose, $client, $context, $deviceName, $deviceId, $deviceIdHash): OtpChallenge {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $id = (string) Str::uuid();
            $code = $this->codeGenerator->generate();

            OtpChallenge::query()
                ->whereBelongsTo($lockedUser)
                ->where('purpose', $purpose->value)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => now()]);

            $challenge = $lockedUser->otpChallenges()->create([
                'id' => $id,
                'purpose' => $purpose,
                'client_type' => $client,
                'actor_context' => $context,
                'device_name' => $deviceName,
                'device_id_hash' => $deviceIdHash ?? ($deviceId === null ? null : hash('sha256', $deviceId)),
                'code_hash' => $this->hasher->make($id, $code),
                'attempts' => 0,
                'max_attempts' => config('authentication.otp.max_attempts'),
                'sent_at' => now(),
                'expires_at' => now()->addMinutes(config('authentication.otp.ttl_minutes')),
            ]);

            DB::afterCommit(function () use ($challenge, $lockedUser, $code): void {
                try {
                    $this->smsSender->send(
                        $lockedUser->phone_number,
                        "Tu código Hipocratiq es {$code}. Expira en ".config('authentication.otp.ttl_minutes').' minutos.',
                    );
                } catch (Throwable $exception) {
                    $challenge->update(['invalidated_at' => now()]);
                    $this->recordEvent->handle($lockedUser, 'otp_delivery_failed', 'failure', [
                        'challenge_id' => $challenge->id,
                        'purpose' => $challenge->purpose->value,
                    ]);
                    Log::error('OTP delivery failed.', [
                        'challenge_id' => $challenge->id,
                        'provider_error' => $exception::class,
                    ]);

                    throw new ServiceUnavailableHttpException(null, 'No se pudo enviar el código OTP.');
                }
            });

            $this->recordEvent->handle($lockedUser, 'otp_sent', metadata: [
                'challenge_id' => $challenge->id,
                'purpose' => $purpose->value,
            ]);

            return $challenge;
        });
    }
}
