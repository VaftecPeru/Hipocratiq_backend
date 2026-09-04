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
use Illuminate\Validation\ValidationException;

final class StartPasswordReset
{
    public function __construct(
        private IssueOtpChallenge $issueOtpChallenge,
        private RecordAuthenticationEvent $recordEvent,
    ) {}

    public function handle(array $data): OtpChallenge
    {
        $identifier = (string) $data['identifier'];
        $client = AuthenticationClient::from($data['client_type'] ?? AuthenticationClient::Web->value);
        $user = str_contains($identifier, '@')
            ? User::query()->where('email', strtolower($identifier))->first()
            : User::query()->where('phone_number', PhoneNumber::fromE164($identifier)->value)->first();

        if (
            $user === null
            || $user->password === null
            || $user->phone_verified_at === null
            || $user->status !== UserStatus::Active
            || ($client === AuthenticationClient::Mobile && ($user?->hasRole(RoleKey::SuperAdmin) ?? false))
        ) {
            $this->recordEvent->handle($user, 'password_reset_failed', 'failure');

            throw ValidationException::withMessages([
                'identifier' => ['No se pudo iniciar la recuperación de contraseña.'],
            ]);
        }

        return $this->issueOtpChallenge->handle(
            $user,
            OtpPurpose::PasswordReset,
            $client,
            AuthenticationContext::Staff,
            $data['device_name'] ?? null,
            $data['device_id'] ?? null,
        );
    }
}
