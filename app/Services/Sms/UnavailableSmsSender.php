<?php

namespace App\Services\Sms;

use App\Contracts\SmsSender;
use RuntimeException;

final class UnavailableSmsSender implements SmsSender
{
    public function send(string $phoneNumber, string $message): void
    {
        throw new RuntimeException('El envío de OTP por teléfono no está configurado. Usa el canal de correo electrónico.');
    }
}
