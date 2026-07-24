<?php

namespace App\Actions\Auth;

use App\Enums\OnboardingStatus;
use App\Enums\OtpPurpose;
use App\Enums\UserStatus;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Services\Auth\OtpHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class VerifyOtpChallenge
{
    public function __construct(
        private OtpHasher $hasher,
        private RecordAuthenticationEvent $recordEvent,
    ) {}

    public function handle(string $challengeId, string $code, OtpPurpose $purpose): User
    {
        $result = DB::transaction(function () use ($challengeId, $code, $purpose): array {
            $challenge = OtpChallenge::query()
                ->with('user')
                ->lockForUpdate()
                ->find($challengeId);

            if ($challenge === null || $challenge->purpose !== $purpose) {
                return ['user' => null, 'error' => 'El código no es válido.'];
            }

            $expectedStatus = $purpose === OtpPurpose::Registration
                ? UserStatus::PendingVerification
                : UserStatus::Active;

            if ($challenge->user->status !== $expectedStatus) {
                return ['user' => $challenge->user, 'error' => 'La cuenta no está disponible.'];
            }

            if ($challenge->consumed_at !== null || $challenge->invalidated_at !== null) {
                return ['user' => $challenge->user, 'error' => 'El código ya no está disponible.'];
            }

            if ($challenge->expires_at->lessThanOrEqualTo(now())) {
                $challenge->update(['invalidated_at' => now()]);

                return ['user' => $challenge->user, 'error' => 'El código ha expirado.'];
            }

            if ($challenge->code_hash === null || ! $this->hasher->verify($challenge->id, $code, $challenge->code_hash)) {
                $attempts = $challenge->attempts + 1;
                $challenge->update([
                    'attempts' => $attempts,
                    'invalidated_at' => $attempts >= $challenge->max_attempts ? now() : null,
                ]);

                return ['user' => $challenge->user, 'error' => 'El código no es válido.'];
            }

            $challenge->update([
                'consumed_at' => now(),
                'code_hash' => null,
            ]);

            if ($purpose === OtpPurpose::Registration) {
                $challenge->user->update([
                    'phone_verified_at' => now(),
                    'status' => UserStatus::Active,
                    'onboarding_status' => OnboardingStatus::PlanSelection,
                ]);
            }

            return ['user' => $challenge->user->fresh(), 'error' => null];
        });

        if ($result['error'] !== null) {
            $this->recordEvent->handle($result['user'], 'otp_failed', 'failure', [
                'challenge_id' => $challengeId,
                'purpose' => $purpose->value,
            ]);

            throw ValidationException::withMessages(['code' => [$result['error']]]);
        }

        $this->recordEvent->handle($result['user'], 'otp_verified', metadata: [
            'challenge_id' => $challengeId,
            'purpose' => $purpose->value,
        ]);

        return $result['user'];
    }
}
