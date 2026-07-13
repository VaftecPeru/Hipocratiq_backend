<?php

namespace App\Services\Sms;

use App\Contracts\SmsSender;

final class ArraySmsSender implements SmsSender
{
    private array $messages = [];

    public function send(string $phoneNumber, string $message): void
    {
        $this->messages[] = [
            'phone_number' => $phoneNumber,
            'message' => $message,
        ];
    }

    public function messages(): array
    {
        return $this->messages;
    }

    public function clear(): void
    {
        $this->messages = [];
    }
}
