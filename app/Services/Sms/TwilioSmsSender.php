<?php

namespace App\Services\Sms;

use App\Contracts\SmsSender;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final readonly class TwilioSmsSender implements SmsSender
{
    public function send(string $phoneNumber, string $message): void
    {
        $accountSid = trim((string) config('sms.twilio.account_sid'));
        $authToken = trim((string) config('sms.twilio.auth_token'));
        $messagingServiceSid = trim((string) config('sms.twilio.messaging_service_sid'));

        if ($accountSid === '' || $authToken === '' || $messagingServiceSid === '') {
            throw new RuntimeException(
                'Twilio no está configurado completamente. Verifica TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN y TWILIO_MESSAGING_SERVICE_SID.',
            );
        }

        Http::asForm()
            ->withBasicAuth($accountSid, $authToken)
            ->connectTimeout(5)
            ->timeout(10)
            ->retry(2, 200, throw: false)
            ->post($this->messagesUrl($accountSid), [
                'MessagingServiceSid' => $messagingServiceSid,
                'To' => $phoneNumber,
                'Body' => $message,
            ])
            ->throw();
    }

    private function messagesUrl(string $accountSid): string
    {
        return 'https://api.twilio.com/2010-04-01/Accounts/'.$accountSid.'/Messages.json';
    }
}
