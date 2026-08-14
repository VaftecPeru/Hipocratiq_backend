<?php

namespace App\Actions\Auth;

use App\Enums\AuthenticationClient;
use App\Enums\AuthenticationContext;
use App\Enums\OtpPurpose;
use App\Enums\RoleKey;
use App\Enums\UserStatus;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class StartLogin
{
    public function __construct(
        private IssueOtpChallenge $issueOtpChallenge,
        private RecordAuthenticationEvent $recordEvent,
    ) {}

    public function handle(array $data): OtpChallenge
    {
        $context = AuthenticationContext::from($data['actor_context'] ?? AuthenticationContext::Staff->value);
        $client = AuthenticationClient::from($data['client_type'] ?? AuthenticationClient::Web->value);
        $user = $this->findUser($data);
        $isClinicAdmin = $user?->hasActiveClinicAdminMembership() ?? false;
        $isReceptionist = $user?->hasActiveReceptionistAccess() ?? false;
        $isDoctor = $user?->hasActiveDoctorAccess() ?? false;
        $resolvedContext = match (true) {
            $context === AuthenticationContext::Patient => AuthenticationContext::Patient,
            $isClinicAdmin => AuthenticationContext::ClinicAdmin,
            $isReceptionist => AuthenticationContext::Receptionist,
            $isDoctor => AuthenticationContext::Doctor,
            default => $context,
        };

        if (
            $user === null
            || ! in_array($context, [AuthenticationContext::Patient, AuthenticationContext::Staff], true)
            || $user->phone_verified_at === null
            || $user->status !== UserStatus::Active
            || ($context === AuthenticationContext::Patient && (
                ! $user->patientProfile()->exists()
                || $user->hasRole(RoleKey::SuperAdmin)
                || $isClinicAdmin
                || $isReceptionist
                || $isDoctor
            ))
            || ($context === AuthenticationContext::Staff && (
                (! $user->hasRole(RoleKey::SuperAdmin) && ! $isClinicAdmin && ! $isReceptionist && ! $isDoctor)
                || $client !== AuthenticationClient::Web
                || $user->password === null
                || ! isset($data['password'])
                || ! Hash::check($data['password'], $user->password)
            ))
        ) {
            $this->recordEvent->handle($user, 'login_failed', 'failure');

            $field = isset($data['identifier']) ? 'identifier' : 'phone_number';

            throw ValidationException::withMessages([
                $field => ['Las credenciales proporcionadas no son correctas.'],
            ]);
        }

        return $this->issueOtpChallenge->handle(
            $user,
            OtpPurpose::Login,
            $client,
            $resolvedContext,
            $data['device_name'] ?? null,
            $data['device_id'] ?? null,
        );
    }

    private function findUser(array $data): ?User
    {
        if (isset($data['identifier'])) {
            $identifier = (string) $data['identifier'];

            return str_contains($identifier, '@')
                ? User::query()->where('email', strtolower($identifier))->first()
                : User::query()->where('phone_number', PhoneNumber::fromE164($identifier)->value)->first();
        }

        $phoneNumber = PhoneNumber::from($data['phone_number'], $data['country_code']);

        return User::query()->where('phone_number', $phoneNumber->value)->first();
    }
}
