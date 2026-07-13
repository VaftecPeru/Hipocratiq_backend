<?php

namespace App\Contracts;

interface SmsSender
{
    public function send(string $phoneNumber, string $message): void;
}
