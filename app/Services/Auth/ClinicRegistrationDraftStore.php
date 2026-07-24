<?php

namespace App\Services\Auth;

use App\Contracts\SmsSender;
use Illuminate\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

final class ClinicRegistrationDraftStore
{
    private const int DraftTtlSeconds = 1800;

    public function __construct(
        private OtpCodeGenerator $codeGenerator,
        private OtpHasher $hasher,
        private SmsSender $smsSender,
    ) {}

    public function create(array $data): string
    {
        $token = Str::random(64);
        $this->put($token, [
            'data' => $data,
            'plan' => null,
            'otp' => null,
            'resend_count' => 0,
        ]);

        return $token;
    }

    /** @return array<string, mixed> */
    public function get(string $token): array
    {
        $encrypted = Cache::get($this->key($token));

        if (! is_string($encrypted)) {
            throw ValidationException::withMessages(['registration' => ['El registro ha expirado. Inicia nuevamente.']]);
        }

        $draft = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($draft)) {
            throw ValidationException::withMessages(['registration' => ['El registro temporal no es válido.']]);
        }

        return $draft;
    }

    public function update(string $token, array $changes): void
    {
        $this->withLock($token, function () use ($token, $changes): void {
            $draft = $this->get($token);
            $this->put($token, array_replace($draft, $changes));
        });
    }

    /** @return array{challenge_id: string, expires_in: int, resend_after: int} */
    public function issueOtp(string $token, bool $isResend = false): array
    {
        return $this->withLock($token, function () use ($token, $isResend): array {
            $draft = $this->get($token);
            $plan = $draft['plan'] ?? null;

            if (! is_array($plan) || empty($plan['key'])) {
                throw ValidationException::withMessages(['plan' => ['Selecciona un plan antes de verificar el registro.']]);
            }

            $currentOtp = $draft['otp'] ?? null;
            if (is_array($currentOtp) && isset($currentOtp['resend_at']) && now()->timestamp < $currentOtp['resend_at']) {
                throw ValidationException::withMessages(['otp' => ['Espera antes de solicitar otro código.']]);
            }

            $resendCount = (int) ($draft['resend_count'] ?? 0);
            if ($isResend && $resendCount >= config('authentication.otp.max_resends')) {
                throw ValidationException::withMessages(['otp' => ['Alcanzaste el límite de 5 reenvíos para este registro.']]);
            }

            $challengeId = (string) Str::uuid();
            $code = $this->codeGenerator->generate();
            $expiresAt = now()->addMinutes(config('authentication.otp.ttl_minutes'));
            $resendAt = now()->addSeconds(config('authentication.otp.resend_seconds'));

            $draft['otp'] = [
                'challenge_id' => $challengeId,
                'code_hash' => $this->hasher->make($challengeId, $code),
                'attempts' => 0,
                'expires_at' => $expiresAt->timestamp,
                'resend_at' => $resendAt->timestamp,
            ];
            $draft['resend_count'] = $isResend ? $resendCount + 1 : $resendCount;
            $this->put($token, $draft);

            try {
                $this->smsSender->send(
                    $draft['data']['admin_phone_number'],
                    "Tu código Hipocratiq es {$code}. Expira en ".config('authentication.otp.ttl_minutes').' minutos.',
                );
            } catch (Throwable) {
                $draft['otp'] = $currentOtp;
                $draft['resend_count'] = $resendCount;
                $this->put($token, $draft);

                throw new ServiceUnavailableHttpException(null, 'No se pudo enviar el código OTP.');
            }

            return [
                'challenge_id' => $challengeId,
                'expires_in' => max(0, $expiresAt->timestamp - now()->timestamp),
                'resend_after' => config('authentication.otp.resend_seconds'),
                'resends_remaining' => max(0, config('authentication.otp.max_resends') - $draft['resend_count']),
            ];
        });
    }

    /** @return array<string, mixed> */
    public function verifyOtp(string $token, string $challengeId, string $code): array
    {
        return $this->withLock($token, function () use ($token, $challengeId, $code): array {
            $draft = $this->get($token);
            $otp = $draft['otp'] ?? null;

            if (! is_array($otp) || $otp['challenge_id'] !== $challengeId || $otp['expires_at'] <= now()->timestamp) {
                throw ValidationException::withMessages(['code' => ['El código no es válido o ha expirado.']]);
            }

            if ($otp['attempts'] >= config('authentication.otp.max_attempts')) {
                throw ValidationException::withMessages(['code' => ['Se excedió el máximo de intentos. Solicita un nuevo código.']]);
            }

            if (! $this->hasher->verify($challengeId, $code, $otp['code_hash'])) {
                $otp['attempts']++;
                $draft['otp'] = $otp;
                $this->put($token, $draft);

                throw ValidationException::withMessages(['code' => ['El código no es válido.']]);
            }

            $draft['otp'] = null;
            $this->put($token, $draft);

            return $draft;
        });
    }

    public function forget(string $token): void
    {
        Cache::forget($this->key($token));
    }

    private function key(string $token): string
    {
        return 'clinic-registration:'.hash('sha256', $token);
    }

    private function put(string $token, array $draft): void
    {
        Cache::put(
            $this->key($token),
            Crypt::encryptString(json_encode($draft, JSON_THROW_ON_ERROR)),
            self::DraftTtlSeconds,
        );
    }

    private function withLock(string $token, callable $callback): mixed
    {
        try {
            return Cache::lock($this->key($token).':lock', 10)->block(3, $callback);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['registration' => ['El registro está siendo procesado. Inténtalo nuevamente.']]);
        }
    }
}
