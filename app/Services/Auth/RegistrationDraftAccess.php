<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class RegistrationDraftAccess
{
    public function token(Request $request, string $cookieName): string
    {
        $headerToken = $request->header('X-Registration-Token');

        if (is_string($headerToken) && $headerToken !== '') {
            if (preg_match('/\A[A-Za-z0-9]{64}\z/', $headerToken) !== 1) {
                throw ValidationException::withMessages([
                    'registration' => ['El registro temporal no es válido.'],
                ]);
            }

            return $headerToken;
        }

        $cookieToken = $request->cookie($cookieName);

        if (! is_string($cookieToken) || preg_match('/\A[A-Za-z0-9]{64}\z/', $cookieToken) !== 1) {
            throw ValidationException::withMessages([
                'registration' => ['El registro temporal no está disponible.'],
            ]);
        }

        return $cookieToken;
    }

    /** @param array<string, mixed> $draft */
    public function assertDevice(Request $request, array $draft): void
    {
        $data = $draft['data'] ?? null;

        if (! is_array($data) || ($data['client_type'] ?? 'web') !== 'mobile') {
            return;
        }

        if (! is_string($request->header('X-Registration-Token'))
            || ! $this->deviceMatches($request, $data)) {
            throw ValidationException::withMessages([
                'registration' => ['El registro temporal no pertenece a este dispositivo.'],
            ]);
        }
    }

    /** @param array<string, mixed> $draft */
    public function isMobile(array $draft): bool
    {
        return is_array($draft['data'] ?? null)
            && ($draft['data']['client_type'] ?? 'web') === 'mobile';
    }

    /** @param array<string, mixed> $data */
    private function deviceMatches(Request $request, array $data): bool
    {
        $deviceName = $request->header('X-Device-Name');
        $deviceId = $request->header('X-Device-ID');

        return is_string($deviceName)
            && is_string($deviceId)
            && trim($deviceName) === ($data['device_name'] ?? null)
            && hash_equals((string) ($data['device_id_hash'] ?? ''), hash('sha256', $deviceId));
    }
}
