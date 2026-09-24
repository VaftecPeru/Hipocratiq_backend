<?php

namespace App\Contracts;

interface RegistrationOtpSender
{
    public function send(string $email, string $recipientName, string $code): void;
}
