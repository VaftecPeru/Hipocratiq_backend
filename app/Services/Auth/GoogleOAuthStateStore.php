<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class GoogleOAuthStateStore
{
    private const int AuthorizationTtlSeconds = 600;
    private const int HandoffTtlSeconds = 300;
    private const int RegistrationTtlSeconds = 1800;

    public function createAuthorization(array $payload): string
    {
        return $this->put('authorization', $payload, self::AuthorizationTtlSeconds);
    }

    public function consumeAuthorization(string $state): array
    {
        return $this->pull('authorization', $state, 'La solicitud de Google expiró. Inicia nuevamente.');
    }

    public function createHandoff(array $payload): string
    {
        return $this->put('handoff', $payload, self::HandoffTtlSeconds);
    }

    public function consumeHandoff(string $token): array
    {
        return $this->pull('handoff', $token, 'La autenticación de Google expiró. Inicia nuevamente.');
    }

    public function createRegistration(array $payload): string
    {
        return $this->put('registration', $payload, self::RegistrationTtlSeconds);
    }

    public function registration(string $token, string $expectedType): array
    {
        $payload = $this->get('registration', $token, 'La identidad de Google expiró. Inicia nuevamente.');

        if (($payload['registration_type'] ?? null) !== $expectedType) {
            throw ValidationException::withMessages([
                'google_registration_token' => ['La identidad de Google no corresponde a este tipo de registro.'],
            ]);
        }

        return $payload;
    }

    public function consumeRegistration(string $token): void
    {
        Cache::forget($this->key('registration', $token));
    }

    private function put(string $namespace, array $payload, int $ttl): string
    {
        $token = Str::random(64);
        Cache::put(
            $this->key($namespace, $token),
            Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
            $ttl,
        );

        return $token;
    }

    private function get(string $namespace, string $token, string $message): array
    {
        if (! preg_match('/^[A-Za-z0-9]{64}$/', $token)) {
            throw ValidationException::withMessages(['google' => [$message]]);
        }

        $encrypted = Cache::get($this->key($namespace, $token));

        if (! is_string($encrypted)) {
            throw ValidationException::withMessages(['google' => [$message]]);
        }

        $payload = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload)) {
            throw ValidationException::withMessages(['google' => [$message]]);
        }

        return $payload;
    }

    private function pull(string $namespace, string $token, string $message): array
    {
        $payload = $this->get($namespace, $token, $message);
        Cache::forget($this->key($namespace, $token));

        return $payload;
    }

    private function key(string $namespace, string $token): string
    {
        return 'google-oauth:'.$namespace.':'.hash('sha256', $token);
    }
}
