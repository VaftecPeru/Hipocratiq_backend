<?php

namespace App\Services\Auth;

use App\Contracts\RegistrationOtpSender;
use App\Notifications\OtpCodeNotification;
use Illuminate\Support\Facades\Notification;

final class EmailRegistrationOtpSender implements RegistrationOtpSender
{
    public function send(string $email, string $recipientName, string $code): void
    {
        Notification::route('mail', $email)
            ->notify(new OtpCodeNotification(
                $code,
                (int) config('authentication.otp.ttl_minutes'),
                $recipientName,
            ));
    }
}
