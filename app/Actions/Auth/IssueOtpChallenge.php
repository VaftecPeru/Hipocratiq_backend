<?php

namespace App\Actions\Auth;

use App\Contracts\PhoneOtpProvider;
use App\Enums\AuthenticationClient;
use App\Enums\AuthenticationContext;
use App\Enums\OtpDeliveryChannel;
use App\Enums\OtpDeliveryStatus;
use App\Enums\OtpPurpose;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Notifications\OtpCodeNotification;
use App\Services\Auth\OtpCodeGenerator;
use App\Services\Auth\OtpHasher;
use App\Services\Auth\PhoneOtpDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

final class IssueOtpChallenge
{
    public function __construct(
        private OtpCodeGenerator $codeGenerator,
        private OtpHasher $hasher,
        private PhoneOtpProvider $phoneOtpProvider,
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
        ?string $flowId = null,
        int $resendCount = 0,
        ?string $expectedLatestChallengeId = null,
        OtpDeliveryChannel $deliveryChannel = OtpDeliveryChannel::Phone,
    ): OtpChallenge {
        [$challenge, $lockedUser] = DB::transaction(function () use (
            $user,
            $purpose,
            $client,
            $context,
            $deviceName,
            $deviceId,
            $deviceIdHash,
            $flowId,
            $resendCount,
            $expectedLatestChallengeId,
            $deliveryChannel,
        ): array {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $latestChallenge = OtpChallenge::query()
                ->whereBelongsTo($lockedUser)
                ->where('purpose', $purpose->value)
                ->orderByDesc('delivery_sequence')
                ->first();

            if (
                $expectedLatestChallengeId !== null
                && $latestChallenge?->id !== $expectedLatestChallengeId
            ) {
                throw ValidationException::withMessages([
                    'challenge_id' => ['El desafío OTP fue reemplazado. Solicita el código más reciente.'],
                ]);
            }

            if ($deliveryChannel === OtpDeliveryChannel::Email && blank($lockedUser->email)) {
                throw ValidationException::withMessages([
                    'identifier' => ['La cuenta no tiene un correo electrónico disponible para recibir el código.'],
                ]);
            }

            $id = (string) Str::uuid();
            $challenge = $lockedUser->otpChallenges()->create([
                'id' => $id,
                'flow_id' => $flowId ?? $id,
                'purpose' => $purpose,
                'client_type' => $client,
                'actor_context' => $context,
                'delivery_channel' => $deliveryChannel,
                'verification_provider' => $deliveryChannel === OtpDeliveryChannel::Email
                    ? 'local_email'
                    : $this->phoneOtpProvider->key(),
                'provider_message_id' => null,
                'provider_verification_id' => null,
                'device_name' => $deviceName,
                'device_id_hash' => $deviceIdHash ?? ($deviceId === null ? null : hash('sha256', $deviceId)),
                'code_hash' => null,
                'attempts' => 0,
                'max_attempts' => config('authentication.otp.max_attempts'),
                'resend_count' => $expectedLatestChallengeId === null
                    ? $resendCount
                    : ($latestChallenge?->resend_count ?? $resendCount) + 1,
                'delivery_status' => OtpDeliveryStatus::Pending,
                'delivery_sequence' => ($latestChallenge?->delivery_sequence ?? 0) + 1,
                'sent_at' => now(),
                'expires_at' => now()->addMinutes(config('authentication.otp.ttl_minutes')),
            ]);

            return [$challenge, $lockedUser];
        });

        try {
            $delivery = $this->deliverCode($lockedUser, $challenge, $deliveryChannel);
        } catch (Throwable $exception) {
            $this->discardPendingChallenge($challenge->id);

            Log::error('OTP delivery failed.', [
                'challenge_id' => $challenge->id,
                'delivery_channel' => $deliveryChannel->value,
                'verification_provider' => $challenge->verification_provider,
                'provider_error' => $exception::class,
            ]);

            throw new ServiceUnavailableHttpException(null, 'No se pudo enviar el código OTP.', $exception);
        }

        $confirmedChallenge = DB::transaction(function () use ($challenge, $user, $purpose, $delivery): ?OtpChallenge {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $pendingChallenge = OtpChallenge::query()
                ->lockForUpdate()
                ->find($challenge->id);

            if (
                $pendingChallenge === null
                || $pendingChallenge->delivery_status !== OtpDeliveryStatus::Pending
            ) {
                return null;
            }

            $latestChallenge = OtpChallenge::query()
                ->whereBelongsTo($lockedUser)
                ->where('purpose', $purpose->value)
                ->orderByDesc('delivery_sequence')
                ->first();

            if (
                $latestChallenge?->id !== $pendingChallenge->id
                || $pendingChallenge->verification_provider !== $delivery->provider
            ) {
                $pendingChallenge->update([
                    'delivery_status' => OtpDeliveryStatus::Failed,
                    'invalidated_at' => now(),
                    'code_hash' => null,
                ]);

                return null;
            }

            OtpChallenge::query()
                ->whereBelongsTo($lockedUser)
                ->where('purpose', $purpose->value)
                ->whereKeyNot($pendingChallenge->id)
                ->where('delivery_status', OtpDeliveryStatus::Sent->value)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => now()]);

            $pendingChallenge->update([
                'delivery_status' => OtpDeliveryStatus::Sent,
                'sent_at' => now(),
                'code_hash' => $delivery->codeHash,
                'verification_provider' => $delivery->provider,
                'provider_message_id' => $delivery->messageId,
                'provider_verification_id' => $delivery->verificationId,
            ]);

            return $pendingChallenge->fresh();
        });

        if ($confirmedChallenge === null) {
            throw new ServiceUnavailableHttpException(
                null,
                'El código OTP fue reemplazado antes de confirmar su entrega.',
            );
        }

        $this->recordEvent->handleSafely($user, 'otp_sent', metadata: [
            'challenge_id' => $confirmedChallenge->id,
            'purpose' => $purpose->value,
            'delivery_channel' => $deliveryChannel->value,
            'verification_provider' => $confirmedChallenge->verification_provider,
        ]);

        return $confirmedChallenge;
    }

    private function deliverCode(
        User $user,
        OtpChallenge $challenge,
        OtpDeliveryChannel $deliveryChannel,
    ): PhoneOtpDelivery {
        if ($deliveryChannel === OtpDeliveryChannel::Email) {
            $code = $this->codeGenerator->generate();

            $user->notify(new OtpCodeNotification(
                $code,
                (int) config('authentication.otp.ttl_minutes'),
            ));

            return new PhoneOtpDelivery(
                provider: 'local_email',
                codeHash: $this->hasher->make($challenge->id, $code),
            );
        }

        if (! is_string($user->phone_number) || $user->phone_number === '') {
            throw new RuntimeException('La cuenta no tiene un teléfono disponible para recibir el código OTP.');
        }

        return $this->phoneOtpProvider->issue($user->phone_number, $challenge->id);
    }

    private function discardPendingChallenge(string $challengeId): void
    {
        DB::transaction(function () use ($challengeId): void {
            $challenge = OtpChallenge::query()
                ->lockForUpdate()
                ->find($challengeId);

            if ($challenge?->delivery_status === OtpDeliveryStatus::Pending) {
                $challenge->delete();
            }
        });
    }
}
