<?php

namespace Tests\Feature\Auth;

use App\Services\Auth\AwsOtpReadiness;
use Aws\EndUserMessaging\EndUserMessagingClient;
use Tests\TestCase;

class AwsOtpReadinessTest extends TestCase
{
    public function test_readiness_is_green_when_aws_otp_requirements_are_complete(): void
    {
        config()->set('authentication.otp.phone_provider', 'aws_notify');
        config()->set('authentication.otp.fixed_code', null);
        config()->set('authentication.otp.ttl_minutes', 5);
        config()->set('authentication.otp.max_attempts', 5);
        config()->set('authentication.otp.aws_notify.region', 'us-east-2');
        config()->set(
            'authentication.otp.aws_notify.origination_identity',
            'arn:aws:sms-voice:us-east-2:123456789012:phone-number/pn-test',
        );
        config()->set('authentication.otp.aws_notify.notify_code_configuration', 'ncc-test');
        config()->set('authentication.otp.aws_notify.configuration_set_name', null);

        $status = app(AwsOtpReadiness::class)->status();

        $this->assertTrue(class_exists(EndUserMessagingClient::class));
        $this->assertTrue($status['ready']);
        $this->assertTrue($status['active']);
        $this->assertNotContains(false, $status['checks'], true);
    }

    public function test_readiness_fails_when_provider_or_security_policy_is_incomplete(): void
    {
        config()->set('authentication.otp.phone_provider', 'local');
        config()->set('authentication.otp.fixed_code', '123456');
        config()->set('authentication.otp.ttl_minutes', 10);
        config()->set('authentication.otp.max_attempts', 3);
        config()->set('authentication.otp.aws_notify.region', '');
        config()->set('authentication.otp.aws_notify.origination_identity', null);
        config()->set('authentication.otp.aws_notify.notify_code_configuration', null);

        $status = app(AwsOtpReadiness::class)->status();

        $this->assertFalse($status['ready']);
        $this->assertFalse($status['active']);
        $this->assertFalse($status['checks']['region_configured']);
        $this->assertFalse($status['checks']['origination_identity_configured']);
        $this->assertFalse($status['checks']['notify_code_configuration_configured']);
        $this->assertFalse($status['checks']['fixed_code_disabled']);
        $this->assertFalse($status['checks']['ttl_is_five_minutes']);
        $this->assertFalse($status['checks']['max_attempts_is_five']);
    }
}
