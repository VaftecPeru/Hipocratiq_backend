<?php

namespace App\Services\Auth;

use App\Contracts\PhoneOtpProvider;
use Aws\EndUserMessaging\EndUserMessagingClient;
use LogicException;

final class AwsEndUserMessagingOtpProvider implements PhoneOtpProvider
{
    private EndUserMessagingClient $client;

    public function __construct()
    {
        if (! class_exists(EndUserMessagingClient::class)) {
            throw new LogicException(
                'AWS End User Messaging requiere instalar aws/aws-sdk-php antes de habilitar OTP_PHONE_PROVIDER=aws_notify.',
            );
        }

        $this->client = new EndUserMessagingClient([
            'version' => '2026-09-21',
            'region' => $this->requiredConfig('region'),
        ]);
    }

    public function key(): string
    {
        return 'aws_notify';
    }

    public function issue(string $phoneNumber, string $referenceId): PhoneOtpDelivery
    {
        $parameters = [
            'channel' => 'TEXT',
            'destinationIdentity' => $phoneNumber,
            'originationIdentity' => $this->requiredConfig('origination_identity'),
            'notifyCodeConfiguration' => $this->requiredConfig('notify_code_configuration'),
            'referenceId' => $referenceId,
        ];

        $configurationSetName = config('authentication.otp.aws_notify.configuration_set_name');

        if (is_string($configurationSetName) && $configurationSetName !== '') {
            $parameters['configurationSetName'] = $configurationSetName;
        }

        $result = $this->client->sendNotifyCodeVerification($parameters);

        return new PhoneOtpDelivery(
            provider: $this->key(),
            messageId: isset($result['messageId']) ? (string) $result['messageId'] : null,
            verificationId: isset($result['verificationId']) ? (string) $result['verificationId'] : null,
        );
    }

    public function verify(
        string $phoneNumber,
        string $referenceId,
        string $code,
        ?string $codeHash = null,
    ): bool {
        $result = $this->client->validateNotifyCodeVerification([
            'code' => $code,
            'destinationIdentity' => $phoneNumber,
            'referenceId' => $referenceId,
        ]);

        return ($result['status'] ?? null) === 'VALID';
    }

    private function requiredConfig(string $key): string
    {
        $value = config("authentication.otp.aws_notify.{$key}");

        if (! is_string($value) || $value === '') {
            throw new LogicException("La configuración AWS Notify [{$key}] no está definida.");
        }

        return $value;
    }
}
