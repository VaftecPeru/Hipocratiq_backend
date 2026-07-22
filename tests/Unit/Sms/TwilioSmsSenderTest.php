<?php

namespace Tests\Unit\Sms;

use App\Services\Sms\TwilioSmsSender;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

class TwilioSmsSenderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sms.twilio', [
            'account_sid' => 'AC123',
            'auth_token' => 'secret-token',
            'messaging_service_sid' => 'MG123',
        ]);
    }

    public function test_it_sends_form_encoded_sms_with_basic_authentication(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201),
        ]);

        (new TwilioSmsSender)->send('+51900112844', 'Tu código es 123456.');

        Http::assertSent(fn (Request $request): bool => $request->url()
            === 'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages.json'
            && $request->hasHeader('Authorization')
            && $request['MessagingServiceSid'] === 'MG123'
            && $request['To'] === '+51900112844'
            && $request['Body'] === 'Tu código es 123456.');
    }

    public function test_it_retries_temporary_provider_failure(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::sequence()
                ->push(['message' => 'temporary'], 500)
                ->push(['sid' => 'SM123'], 201),
        ]);

        (new TwilioSmsSender)->send('+51900112844', 'Mensaje');

        Http::assertSentCount(2);
    }

    public function test_it_throws_when_provider_keeps_failing(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response(['message' => 'failure'], 500),
        ]);
        $this->expectException(RequestException::class);

        (new TwilioSmsSender)->send('+51900112844', 'Mensaje');
    }

    public function test_it_rejects_missing_credentials(): void
    {
        config()->set('sms.twilio.auth_token');
        $this->expectException(LogicException::class);

        new TwilioSmsSender;
    }
}
