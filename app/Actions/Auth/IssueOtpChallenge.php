<?php

namespace App\Actions\Auth;

use App\Contracts\SmsSender;
use App\Enums\OtpPurpose;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use App\Services\Auth\OtpHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class IssueOtpChallenge
{
    public function __construct(
        private OtpCodeGenerator $codeGenerator,
        private OtpHasher $hasher,
        private SmsSender $smsSender,
        private RecordAuthenticationEvent $recordEvent,
    ) {}

    public function handle(User $user, OtpPurpose $purpose): OtpChallenge
    {
        return DB::transaction(function () use ($user, $purpose): OtpChallenge {
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
                'code_hash' => $this->hasher->make($id, $code),
                'attempts' => 0,
                'max_attempts' => config('authentication.otp.max_attempts'),
                'sent_at' => now(),
                'expires_at' => now()->addMinutes(config('authentication.otp.ttl_minutes')),
            ]);

            DB::afterCommit(fn () => $this->smsSender->send(
                $lockedUser->phone_number,
                "Tu código MEDIQ es {$code}. Expira en ".config('authentication.otp.ttl_minutes').' minutos.',
            ));

            $this->recordEvent->handle($lockedUser, 'otp_sent', metadata: [
                'challenge_id' => $challenge->id,
                'purpose' => $purpose->value,
            ]);

            return $challenge;
        });
    }
}
