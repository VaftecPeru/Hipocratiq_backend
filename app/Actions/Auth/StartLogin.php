<?php

namespace App\Actions\Auth;

use App\Enums\OtpPurpose;
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
        $phoneNumber = PhoneNumber::from($data['phone_number'], $data['country_code']);
        $user = User::query()->where('phone_number', $phoneNumber->value)->first();

        if (
            $user === null
            || ! Hash::check($data['password'], $user->password)
            || $user->phone_verified_at === null
            || $user->status !== UserStatus::Active
        ) {
            $this->recordEvent->handle($user, 'login_failed', 'failure');

            throw ValidationException::withMessages([
                'phone_number' => ['Las credenciales proporcionadas no son correctas.'],
            ]);
        }

        return $this->issueOtpChallenge->handle($user, OtpPurpose::Login);
    }
}
