<?php

namespace App\Services\Auth;

use App\Contracts\PhoneOtpProvider;
use App\Contracts\SmsSender;

final class LocalPhoneOtpProvider implements PhoneOtpProvider
{
    public function __construct(
        private OtpCodeGenerator $codeGenerator,
        private OtpHasher $hasher,
        private SmsSender $smsSender,
    ) {}

    public function key(): string
    {
        return 'local_sms';
    }

    public function issue(string $phoneNumber, string $referenceId): PhoneOtpDelivery
    {
        $code = $this->codeGenerator->generate();

        $this->smsSender->send(
            $phoneNumber,
            "Tu código Hipocratiq es {$code}. Expira en ".config('authentication.otp.ttl_minutes').' minutos.',
        );

        return new PhoneOtpDelivery(
            provider: $this->key(),
            codeHash: $this->hasher->make($referenceId, $code),
        );
    }

    public function verify(
        string $phoneNumber,
        string $referenceId,
        string $code,
        ?string $codeHash = null,
    ): bool {
        return is_string($codeHash)
            && $codeHash !== ''
            && $this->hasher->verify($referenceId, $code, $codeHash);
    }
}
