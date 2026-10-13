<?php

namespace App\Actions\Auth;

use App\Contracts\PhoneOtpProvider;
use App\Enums\AuthenticationClient;
use App\Enums\OnboardingStatus;
use App\Enums\OtpDeliveryChannel;
use App\Enums\OtpDeliveryStatus;
use App\Enums\OtpPurpose;
use App\Enums\UserStatus;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Services\Auth\OtpHasher;
use App\Support\Auth\MobileDeviceProof;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

final class VerifyOtpChallenge
{
    public function __construct(
        private OtpHasher $hasher,
        private PhoneOtpProvider $phoneOtpProvider,
        private RecordAuthenticationEvent $recordEvent,
    ) {}

    public function handle(
        string $challengeId,
        string $code,
        OtpPurpose $purpose,
        AuthenticationClient $client = AuthenticationClient::Web,
        ?string $deviceName = null,
        ?string $deviceId = null,
    ): User {
        $reservation = DB::transaction(function () use (
            $challengeId,
            $purpose,
            $client,
            $deviceName,
            $deviceId,
        ): array {
            $challenge = OtpChallenge::query()
                ->with('user')
                ->lockForUpdate()
                ->find($challengeId);

            $error = $this->challengeError(
                $challenge,
                $purpose,
                $client,
                $deviceName,
                $deviceId,
            );

            if ($error !== null) {
                return [
                    'user' => $challenge?->user,
                    'error' => $error,
                ];
            }

            return [
                'user' => $challenge->user,
                'error' => null,
                'delivery_channel' => $challenge->delivery_channel,
                'verification_provider' => $challenge->verification_provider,
                'phone_number' => $challenge->user->phone_number,
                'code_hash' => $challenge->code_hash,
            ];
        });

        if ($reservation['error'] !== null) {
            $this->recordFailure($reservation['user'], $challengeId, $purpose, $reservation['error']);
        }

        try {
            $matches = $this->codeMatches($reservation, $challengeId, $code);
        } catch (Throwable $exception) {
            Log::error('OTP verification provider failed.', [
                'challenge_id' => $challengeId,
                'verification_provider' => $reservation['verification_provider'] ?? null,
                'provider_error' => $exception::class,
            ]);

            throw new ServiceUnavailableHttpException(
                null,
                'No se pudo validar el código OTP.',
                $exception,
            );
        }

        $result = DB::transaction(function () use (
            $challengeId,
            $purpose,
            $client,
            $deviceName,
            $deviceId,
            $matches,
        ): array {
            $challenge = OtpChallenge::query()
                ->with('user')
                ->lockForUpdate()
                ->find($challengeId);

            $error = $this->challengeError(
                $challenge,
                $purpose,
                $client,
                $deviceName,
                $deviceId,
            );

            if ($error !== null) {
                return [
                    'user' => $challenge?->user,
                    'error' => $error,
                ];
            }

            if (! $matches) {
                $attempts = $challenge->attempts + 1;

                $challenge->update([
                    'attempts' => $attempts,
                    'invalidated_at' => $attempts >= $challenge->max_attempts ? now() : null,
                ]);

                return [
                    'user' => $challenge->user,
                    'error' => 'El código no es válido.',
                ];
            }

            $challenge->update([
                'consumed_at' => now(),
                'code_hash' => null,
            ]);

            if ($challenge->delivery_channel === OtpDeliveryChannel::Email
                && $challenge->user->email_verified_at === null) {
                $challenge->user->update(['email_verified_at' => now()]);
            }

            if ($purpose === OtpPurpose::Registration) {
                $challenge->user->update([
                    'phone_verified_at' => now(),
                    'status' => UserStatus::Active,
                    'onboarding_status' => OnboardingStatus::PlanSelection,
                ]);
            }

            return [
                'user' => $challenge->user->fresh(),
                'error' => null,
            ];
        });

        if ($result['error'] !== null) {
            $this->recordFailure($result['user'], $challengeId, $purpose, $result['error']);
        }

        $this->recordEvent->handle($result['user'], 'otp_verified', metadata: [
            'challenge_id' => $challengeId,
            'purpose' => $purpose->value,
        ]);

        return $result['user'];
    }

    private function challengeError(
        ?OtpChallenge $challenge,
        OtpPurpose $purpose,
        AuthenticationClient $client,
        ?string $deviceName,
        ?string $deviceId,
    ): ?string {
        if ($challenge === null || $challenge->purpose !== $purpose) {
            return 'El código no es válido.';
        }

        if ($challenge->client_type !== $client || ($client === AuthenticationClient::Mobile && ! MobileDeviceProof::matches(
            $challenge->device_name,
            $challenge->device_id_hash,
            $deviceName,
            $deviceId,
        ))) {
            return 'El código no es válido.';
        }

        $expectedStatus = $purpose === OtpPurpose::Registration
            ? UserStatus::PendingVerification
            : UserStatus::Active;

        if ($challenge->user->status !== $expectedStatus) {
            return 'La cuenta no está disponible.';
        }

        if ($challenge->delivery_status !== OtpDeliveryStatus::Sent) {
            return 'El código aún no está disponible.';
        }

        if ($challenge->consumed_at !== null || $challenge->invalidated_at !== null) {
            return 'El código ya no está disponible.';
        }

        if ($challenge->expires_at->lessThanOrEqualTo(now())) {
            $challenge->update(['invalidated_at' => now()]);

            return 'El código ha expirado.';
        }

        return null;
    }

    private function codeMatches(array $reservation, string $challengeId, string $code): bool
    {
        if ($reservation['delivery_channel'] === OtpDeliveryChannel::Email) {
            $codeHash = $reservation['code_hash'];

            return is_string($codeHash)
                && $codeHash !== ''
                && $this->hasher->verify($challengeId, $code, $codeHash);
        }

        $providerKey = $reservation['verification_provider'];

        if (! is_string($providerKey) || $providerKey === '') {
            $providerKey = 'local_sms';
        }

        if ($providerKey !== $this->phoneOtpProvider->key()) {
            throw new RuntimeException(
                "El desafío OTP pertenece al proveedor [{$providerKey}], pero el proveedor activo es [{$this->phoneOtpProvider->key()}].",
            );
        }

        $phoneNumber = $reservation['phone_number'];

        if (! is_string($phoneNumber) || $phoneNumber === '') {
            throw new RuntimeException('La cuenta no tiene un teléfono disponible para validar el código OTP.');
        }

        return $this->phoneOtpProvider->verify(
            $phoneNumber,
            $challengeId,
            $code,
            is_string($reservation['code_hash']) ? $reservation['code_hash'] : null,
        );
    }

    private function recordFailure(
        ?User $user,
        string $challengeId,
        OtpPurpose $purpose,
        string $error,
    ): never {
        $this->recordEvent->handle($user, 'otp_failed', 'failure', [
            'challenge_id' => $challengeId,
            'purpose' => $purpose->value,
        ]);

        throw ValidationException::withMessages(['code' => [$error]]);
    }
}
