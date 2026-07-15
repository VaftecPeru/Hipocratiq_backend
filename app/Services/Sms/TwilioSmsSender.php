<?php

namespace App\Services\Sms;

use App\Contracts\SmsSender;
use Illuminate\Support\Facades\Http;
use LogicException;

final readonly class TwilioSmsSender implements SmsSender
{
    public function __construct()
    {
        if (! $this->isConfigured()) {
            throw new LogicException('Las credenciales de Twilio no están configuradas.');
        }
    }

    public function send(string $phoneNumber, string $message): void
    {
        Http::asForm()
            ->withBasicAuth(
                (string) config('sms.twilio.account_sid'),
                (string) config('sms.twilio.auth_token'),
            )
            ->connectTimeout(5)
            ->timeout(10)
            ->retry(2, 200, throw: false)
            ->post($this->messagesUrl(), [
                'MessagingServiceSid' => config('sms.twilio.messaging_service_sid'),
                'To' => $phoneNumber,
                'Body' => $message,
            ])
            ->throw();
    }

    private function isConfigured(): bool
    {
        return collect([
            config('sms.twilio.account_sid'),
            config('sms.twilio.auth_token'),
            config('sms.twilio.messaging_service_sid'),
        ])->every(fn (mixed $value): bool => is_string($value) && $value !== '');
    }

    private function messagesUrl(): string
    {
        return 'https://api.twilio.com/2010-04-01/Accounts/'.config('sms.twilio.account_sid').'/Messages.json';
    }
}
