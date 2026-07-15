<?php

namespace App\Actions\Auth;

use App\Enums\OtpPurpose;
use App\Enums\UserStatus;
use App\Models\OtpChallenge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ResendOtpChallenge
{
    public function __construct(private IssueOtpChallenge $issueOtpChallenge) {}

    public function handle(string $challengeId): OtpChallenge
    {
        return DB::transaction(function () use ($challengeId): OtpChallenge {
            $challenge = OtpChallenge::query()->with('user')->find($challengeId);

            if ($challenge === null || $challenge->consumed_at !== null) {
                throw ValidationException::withMessages([
                    'challenge_id' => ['El desafío OTP no está disponible.'],
                ]);
            }

            $user = User::query()->lockForUpdate()->findOrFail($challenge->user_id);
            $expectedStatus = $challenge->purpose === OtpPurpose::Registration
                ? UserStatus::PendingVerification
                : UserStatus::Active;

            if ($user->status !== $expectedStatus) {
                throw ValidationException::withMessages([
                    'challenge_id' => ['La cuenta no está disponible.'],
                ]);
            }

            $latestChallenge = OtpChallenge::query()
                ->whereBelongsTo($user)
                ->where('purpose', $challenge->purpose->value)
                ->latest('sent_at')
                ->first();

            if ($latestChallenge?->sent_at->addSeconds(config('authentication.otp.resend_seconds'))->isFuture()) {
                throw ValidationException::withMessages([
                    'challenge_id' => ['Espera antes de solicitar otro código.'],
                ]);
            }

            return $this->issueOtpChallenge->handle(
                $user,
                $challenge->purpose,
                $challenge->client_type,
                $challenge->actor_context,
                $challenge->device_name,
                null,
                $challenge->device_id_hash,
            );
        });
    }
}
