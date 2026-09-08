<?php

namespace App\Actions\Auth;

use App\Enums\OnboardingStatus;
use App\Enums\UserStatus;
use App\Models\ReceptionistInvitation;
use App\Models\User;
use App\Support\Auth\MobileDeviceProof;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CompleteReceptionistActivation
{
    public function handle(string $invitationToken, User $user, string $password): User
    {
        return DB::transaction(function () use ($invitationToken, $user, $password): User {
            $invitation = ReceptionistInvitation::query()
                ->where('token_hash', MobileDeviceProof::hashId($invitationToken))
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if ($invitation === null || (int) $invitation->user_id !== (int) $user->id) {
                throw ValidationException::withMessages([
                    'invitation_token' => ['La invitación no está disponible.'],
                ]);
            }

            $user->update([
                'password' => $password,
                'status' => UserStatus::Active,
                'onboarding_status' => OnboardingStatus::Completed,
                'phone_verified_at' => now(),
            ]);
            $invitation->update(['accepted_at' => now()]);

            return $user->fresh();
        });
    }
}
