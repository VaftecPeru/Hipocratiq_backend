<?php

namespace App\Actions\Auth;

use App\Enums\AuthenticationClient;
use App\Enums\AuthenticationContext;
use App\Enums\OnboardingStatus;
use App\Enums\OtpPurpose;
use App\Enums\UserStatus;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RegisterPatient
{
    public function __construct(
        private IssueOtpChallenge $issueOtpChallenge,
        private RecordAuthenticationEvent $recordEvent,
    ) {}

    public function handle(array $data): OtpChallenge
    {
        $phoneNumber = isset($data['country_code'])
            ? PhoneNumber::from($data['phone_number'], $data['country_code'])
            : PhoneNumber::fromE164($data['phone_number']);

        return DB::transaction(function () use ($data, $phoneNumber): OtpChallenge {
            if (User::query()->where('phone_number', $phoneNumber->value)->exists()) {
                throw ValidationException::withMessages([
                    'phone_number' => ['El número de teléfono ya está registrado.'],
                ]);
            }

            $user = User::query()->create([
                'name' => $data['name'],
                'email' => isset($data['email']) ? strtolower($data['email']) : null,
                'phone_number' => $phoneNumber->value,
                'country_code' => $phoneNumber->countryCode,
                'password' => $data['password'] ?? null,
                'status' => UserStatus::PendingVerification,
                'onboarding_status' => OnboardingStatus::PhoneVerification,
            ]);

            $user->patientProfile()->create();
            $this->recordEvent->handle($user, 'registration_started');

            return $this->issueOtpChallenge->handle(
                $user,
                OtpPurpose::Registration,
                AuthenticationClient::from($data['client_type'] ?? AuthenticationClient::Web->value),
                AuthenticationContext::Patient,
                $data['device_name'] ?? null,
                $data['device_id'] ?? null,
            );
        });
    }
}
