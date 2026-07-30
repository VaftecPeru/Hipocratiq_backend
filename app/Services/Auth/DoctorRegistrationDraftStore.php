<?php

namespace App\Services\Auth;

use App\Contracts\SmsSender;
use App\Enums\DoctorPracticeType;
use Illuminate\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

final class DoctorRegistrationDraftStore
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
        $this->put($token, ['data' => $data, 'plan' => null, 'otp' => null, 'resend_count' => 0]);

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

    public function selectPlan(string $token, array $plan): void
    {
        $this->withLock($token, function () use ($token, $plan): void {
            $draft = $this->get($token);

            if (($draft['data']['practice_type'] ?? null) !== DoctorPracticeType::Independent->value) {
                throw ValidationException::withMessages(['plan' => ['La clínica cubre el plan de este médico.']]);
            }

            $draft['plan'] = $plan;
            $this->put($token, $draft);
        });
    }

    /** @return array{challenge_id: string, expires_in: int, resend_after: int, resends_remaining: int} */
    public function issueOtp(string $token, bool $isResend = false): array
    {
        $reservation = $this->withLock($token, function () use ($token, $isResend): array {
            $draft = $this->get($token);
            $isIndependent = ($draft['data']['practice_type'] ?? null) === DoctorPracticeType::Independent->value;

            if ($isIndependent && empty($draft['plan']['key'])) {
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
            $previousOtp = is_array($currentOtp)
                && ($currentOtp['delivery_status'] ?? 'sent') === 'pending'
                ? ($currentOtp['previous_otp'] ?? null)
                : $currentOtp;
            $nextResendCount = $isResend ? $resendCount + 1 : $resendCount;
            $draft['otp'] = [
                'challenge_id' => $challengeId,
                'code_hash' => $this->hasher->make($challengeId, $code),
                'attempts' => 0,
                'expires_at' => $expiresAt->timestamp,
                'resend_at' => $resendAt->timestamp,
                'delivery_status' => 'pending',
                'previous_otp' => $previousOtp,
            ];
            $draft['resend_count'] = $nextResendCount;
            $this->put($token, $draft);

            return [
                'challenge_id' => $challengeId,
                'code' => $code,
                'phone_number' => $draft['data']['doctor_phone_number'],
                'expires_at' => $expiresAt->timestamp,
                'previous_resend_count' => $resendCount,
                'next_resend_count' => $nextResendCount,
            ];
        });

        try {
            $this->smsSender->send(
                $reservation['phone_number'],
                "Tu código Hipocratiq es {$reservation['code']}. Expira en ".config('authentication.otp.ttl_minutes').' minutos.',
            );
        } catch (Throwable $exception) {
            $this->restorePreviousOtp(
                $token,
                $reservation['challenge_id'],
                $reservation['previous_resend_count'],
            );

            throw new ServiceUnavailableHttpException(
                null,
                'No se pudo enviar el código OTP.',
                $exception,
            );
        }

        return $this->withLock($token, function () use ($token, $reservation): array {
            $draft = $this->get($token);
            $otp = $draft['otp'] ?? null;

            if (
                ! is_array($otp)
                || $otp['challenge_id'] !== $reservation['challenge_id']
                || ($otp['delivery_status'] ?? null) !== 'pending'
            ) {
                throw new ServiceUnavailableHttpException(
                    null,
                    'El código OTP fue reemplazado antes de confirmar su entrega.',
                );
            }

            $otp['delivery_status'] = 'sent';
            unset($otp['previous_otp']);
            $draft['otp'] = $otp;
            $this->put($token, $draft);

            return [
                'challenge_id' => $reservation['challenge_id'],
                'expires_in' => max(0, $reservation['expires_at'] - now()->timestamp),
                'resend_after' => config('authentication.otp.resend_seconds'),
                'resends_remaining' => max(
                    0,
                    config('authentication.otp.max_resends') - $reservation['next_resend_count'],
                ),
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

            if (($otp['delivery_status'] ?? 'sent') !== 'sent') {
                throw ValidationException::withMessages(['code' => ['El código aún no está disponible.']]);
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

            return $draft;
        });
    }

    public function consumeVerifiedOtp(string $token, string $challengeId): void
    {
        $this->withLock($token, function () use ($token, $challengeId): void {
            $draft = $this->get($token);
            $otp = $draft['otp'] ?? null;

            if (
                ! is_array($otp)
                || $otp['challenge_id'] !== $challengeId
                || ($otp['delivery_status'] ?? 'sent') !== 'sent'
            ) {
                throw ValidationException::withMessages([
                    'code' => ['El código ya está siendo procesado o no está disponible.'],
                ]);
            }

            $draft['otp'] = null;
            $this->put($token, $draft);
        });
    }

    /** @param array<string, mixed> $otp */
    public function restoreVerifiedOtp(string $token, array $otp): void
    {
        $this->withLock($token, function () use ($token, $otp): void {
            $draft = $this->get($token);

            if (($draft['otp'] ?? null) === null) {
                $draft['otp'] = $otp;
                $this->put($token, $draft);
            }
        });
    }

    public function forget(string $token): void
    {
        Cache::forget($this->key($token));
    }

    private function key(string $token): string
    {
        return 'doctor-registration:'.hash('sha256', $token);
    }

    private function put(string $token, array $draft): void
    {
        Cache::put($this->key($token), Crypt::encryptString(json_encode($draft, JSON_THROW_ON_ERROR)), self::DraftTtlSeconds);
    }

    private function restorePreviousOtp(
        string $token,
        string $challengeId,
        int $previousResendCount,
    ): void {
        $this->withLock($token, function () use ($token, $challengeId, $previousResendCount): void {
            $draft = $this->get($token);
            $otp = $draft['otp'] ?? null;

            if (
                is_array($otp)
                && $otp['challenge_id'] === $challengeId
                && ($otp['delivery_status'] ?? null) === 'pending'
            ) {
                $draft['otp'] = $otp['previous_otp'] ?? null;
                $draft['resend_count'] = $previousResendCount;
                $this->put($token, $draft);
            }
        });
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
