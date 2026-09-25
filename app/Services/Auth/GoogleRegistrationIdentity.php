<?php

namespace App\Services\Auth;

use Illuminate\Validation\ValidationException;

final class GoogleRegistrationIdentity
{
    public function __construct(private GoogleOAuthStateStore $stateStore) {}

    /** @return array{sub: string, email: string, name: string, avatar: string|null} */
    public function resolve(?string $token, string $type, string $email): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }

        $payload = $this->stateStore->registration($token, $type);
        $identity = $payload['identity'] ?? null;

        if (! is_array($identity)
            || ! is_string($identity['sub'] ?? null)
            || ! is_string($identity['email'] ?? null)
            || strtolower($identity['email']) !== strtolower($email)) {
            throw ValidationException::withMessages([
                'google_registration_token' => ['El correo del formulario no coincide con la identidad verificada por Google.'],
            ]);
        }

        return [
            'sub' => $identity['sub'],
            'email' => strtolower($identity['email']),
            'name' => is_string($identity['name'] ?? null) ? $identity['name'] : strtolower($identity['email']),
            'avatar' => is_string($identity['avatar'] ?? null) ? $identity['avatar'] : null,
        ];
    }

    public function consume(?string $token): void
    {
        if (is_string($token) && $token !== '') {
            $this->stateStore->consumeRegistration($token);
        }
    }
}
