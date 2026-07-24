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

            $flowId = $challenge->flow_id ?? $challenge->id;
            $latestChallenge = OtpChallenge::query()
                ->whereBelongsTo($user)
                ->where('purpose', $challenge->purpose->value)
                ->where(function ($query) use ($flowId): void {
                    $query->where('flow_id', $flowId)->orWhere('id', $flowId);
                })
                ->latest('sent_at')
                ->firstOrFail();

            if ($latestChallenge?->sent_at->addSeconds(config('authentication.otp.resend_seconds'))->isFuture()) {
                throw ValidationException::withMessages([
                    'challenge_id' => ['Espera antes de solicitar otro código.'],
                ]);
            }

            if ($latestChallenge->resend_count >= config('authentication.otp.max_resends')) {
                throw ValidationException::withMessages([
                    'challenge_id' => ['Alcanzaste el límite de 5 reenvíos para este desafío.'],
                ]);
            }

            return $this->issueOtpChallenge->handle(
                $user,
                $latestChallenge->purpose,
                $latestChallenge->client_type,
                $latestChallenge->actor_context,
                $latestChallenge->device_name,
                null,
                $latestChallenge->device_id_hash,
                $flowId,
                $latestChallenge->resend_count + 1,
            );
        });
    }
}
