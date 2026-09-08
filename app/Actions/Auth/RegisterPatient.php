<?php

namespace App\Actions\Auth;

use App\Enums\AuthenticationClient;
use App\Enums\AuthenticationContext;
use App\Enums\OnboardingStatus;
use App\Enums\OtpDeliveryStatus;
use App\Enums\OtpPurpose;
use App\Enums\UserStatus;
use App\Models\Clinic;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

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

        $user = DB::transaction(function () use ($data, $phoneNumber): User {
            if (User::query()->where('phone_number', $phoneNumber->value)->exists()) {
                throw ValidationException::withMessages([
                    'phone_number' => ['El número de teléfono ya está registrado.'],
                ]);
            }

            $clinic = Clinic::query()
                ->whereKey($data['clinic_id'])
                ->where('status', 'active')
                ->first();

            if ($clinic === null) {
                throw ValidationException::withMessages([
                    'clinic_id' => ['La clínica seleccionada no está disponible.'],
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

            $user->patientProfile()->create(['clinic_id' => $clinic->id]);
            $this->recordEvent->handle($user, 'registration_started', metadata: [
                'clinic_id' => $clinic->id,
            ]);

            return $user;
        });

        try {
            return $this->issueOtpChallenge->handle(
                $user,
                OtpPurpose::Registration,
                AuthenticationClient::from($data['client_type'] ?? AuthenticationClient::Web->value),
                AuthenticationContext::Patient,
                $data['device_name'] ?? null,
                $data['device_id'] ?? null,
            );
        } catch (Throwable $exception) {
            $this->discardUndeliveredRegistration($user->id);

            throw $exception;
        }
    }

    private function discardUndeliveredRegistration(int $userId): void
    {
        DB::transaction(function () use ($userId): void {
            $user = User::query()->lockForUpdate()->find($userId);

            if (
                $user === null
                || $user->status !== UserStatus::PendingVerification
                || $user->phone_verified_at !== null
            ) {
                return;
            }

            $hasDeliveredChallenge = $user->otpChallenges()
                ->where('delivery_status', OtpDeliveryStatus::Sent->value)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->exists();

            if (! $hasDeliveredChallenge) {
                $user->delete();
            }
        });
    }
}
