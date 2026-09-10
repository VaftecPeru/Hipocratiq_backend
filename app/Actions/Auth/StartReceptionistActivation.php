<?php

namespace App\Actions\Auth;

use App\Enums\AuthenticationClient;
use App\Enums\AuthenticationContext;
use App\Enums\OnboardingStatus;
use App\Enums\OtpPurpose;
use App\Enums\UserStatus;
use App\Models\OtpChallenge;
use App\Models\ReceptionistInvitation;
use App\Models\Role;
use App\Models\User;
use App\Support\Auth\MobileDeviceProof;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StartReceptionistActivation
{
    public function __construct(private IssueOtpChallenge $issueOtpChallenge) {}

    public function handle(string $invitationToken, string $deviceName, string $deviceId): OtpChallenge
    {
        $user = DB::transaction(function () use ($invitationToken): User {
            $invitation = $this->lockInvitation($invitationToken);
            $user = $invitation->user_id === null
                ? $this->createUser($invitation)
                : User::query()->lockForUpdate()->find($invitation->user_id);

            if (! $user instanceof User || $user->status === UserStatus::Active) {
                throw ValidationException::withMessages([
                    'invitation_token' => ['La invitación ya no está disponible.'],
                ]);
            }

            if ($invitation->user_id === null) {
                $invitation->update(['user_id' => $user->id]);
            }

            return $user;
        });

        return $this->issueOtpChallenge->handle(
            $user,
            OtpPurpose::Registration,
            AuthenticationClient::Mobile,
            AuthenticationContext::Receptionist,
            trim($deviceName),
            $deviceId,
        );
    }

    public function assertChallengeBelongsToInvitation(string $invitationToken, string $challengeId): void
    {
        $invitation = $this->findInvitation($invitationToken);

        if ($invitation->user_id === null || $invitation->user === null || ! $invitation->user->otpChallenges()->whereKey($challengeId)->exists()) {
            throw ValidationException::withMessages([
                'invitation_token' => ['La invitación no corresponde al desafío OTP.'],
            ]);
        }
    }

    private function createUser(ReceptionistInvitation $invitation): User
    {
        $role = Role::query()
            ->where('key', 'receptionist')
            ->where('is_system', true)
            ->first();

        if ($role === null) {
            throw ValidationException::withMessages([
                'invitation_token' => ['La invitación no está disponible.'],
            ]);
        }

        $user = User::query()->create([
            'name' => $invitation->name,
            'email' => $invitation->email,
            'phone_number' => $invitation->phone_number,
            'country_code' => $invitation->country_code,
            'password' => Hash::make(Str::random(64)),
            'status' => UserStatus::PendingVerification,
            'onboarding_status' => OnboardingStatus::PhoneVerification,
        ]);
        $user->roles()->attach($role);
        $user->clinicMemberships()->create([
            'clinic_id' => $invitation->clinic_id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        return $user;
    }

    private function lockInvitation(string $invitationToken): ReceptionistInvitation
    {
        return ReceptionistInvitation::query()
            ->with('user')
            ->where('token_hash', MobileDeviceProof::hashId($invitationToken))
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->lockForUpdate()
            ->first() ?? throw ValidationException::withMessages([
                'invitation_token' => ['La invitación no está disponible.'],
            ]);
    }

    private function findInvitation(string $invitationToken): ReceptionistInvitation
    {
        return ReceptionistInvitation::query()
            ->with('user')
            ->where('token_hash', MobileDeviceProof::hashId($invitationToken))
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first() ?? throw ValidationException::withMessages([
                'invitation_token' => ['La invitación no está disponible.'],
            ]);
    }
}
