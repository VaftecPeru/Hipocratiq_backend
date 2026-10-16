<?php

namespace App\Services\Auth;

use Aws\EndUserMessaging\EndUserMessagingClient;

final class AwsOtpReadiness
{
    /**
     * @return array{
     *     ready: bool,
     *     active: bool,
     *     checks: array<string, bool>,
     *     details: array<string, string|int|null>
     * }
     */
    public function status(): array
    {
        $provider = config('authentication.otp.phone_provider');
        $fixedCode = config('authentication.otp.fixed_code');
        $region = config('authentication.otp.aws_notify.region');
        $originationIdentity = config('authentication.otp.aws_notify.origination_identity');
        $notifyCodeConfiguration = config('authentication.otp.aws_notify.notify_code_configuration');
        $configurationSetName = config('authentication.otp.aws_notify.configuration_set_name');
        $ttlMinutes = (int) config('authentication.otp.ttl_minutes');
        $maxAttempts = (int) config('authentication.otp.max_attempts');

        $checks = [
            'sdk_available' => class_exists(EndUserMessagingClient::class),
            'region_configured' => is_string($region) && $region !== '',
            'origination_identity_configured' => is_string($originationIdentity) && $originationIdentity !== '',
            'notify_code_configuration_configured' => is_string($notifyCodeConfiguration) && $notifyCodeConfiguration !== '',
            'fixed_code_disabled' => ! is_string($fixedCode) || $fixedCode === '',
            'ttl_is_five_minutes' => $ttlMinutes === 5,
            'max_attempts_is_five' => $maxAttempts === 5,
        ];

        return [
            'ready' => ! in_array(false, $checks, true),
            'active' => $provider === 'aws_notify',
            'checks' => $checks,
            'details' => [
                'provider' => is_string($provider) ? $provider : null,
                'region' => is_string($region) ? $region : null,
                'configuration_set' => is_string($configurationSetName) && $configurationSetName !== ''
                    ? $configurationSetName
                    : null,
                'ttl_minutes' => $ttlMinutes,
                'max_attempts' => $maxAttempts,
            ],
        ];
    }
}
