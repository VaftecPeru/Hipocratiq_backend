<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class GoogleOAuthClient
{
    public function configured(): bool
    {
        return (bool) config('services.google_oauth.enabled')
            && filled(config('services.google_oauth.client_id'))
            && filled(config('services.google_oauth.client_secret'))
            && filled(config('services.google_oauth.redirect_uri'))
            && filled(config('services.google_oauth.frontend_callback_url'));
    }

    public function authorizationUrl(string $state): string
    {
        $this->assertConfigured();

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => (string) config('services.google_oauth.client_id'),
            'redirect_uri' => (string) config('services.google_oauth.redirect_uri'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account',
            'include_granted_scopes' => 'true',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{sub: string, email: string, name: string, avatar: string|null} */
    public function userFromCode(string $code): array
    {
        $this->assertConfigured();

        $tokenResponse = Http::asForm()
            ->acceptJson()
            ->timeout(15)
            ->post('https://oauth2.googleapis.com/token', [
                'code' => $code,
                'client_id' => (string) config('services.google_oauth.client_id'),
                'client_secret' => (string) config('services.google_oauth.client_secret'),
                'redirect_uri' => (string) config('services.google_oauth.redirect_uri'),
                'grant_type' => 'authorization_code',
            ]);

        if (! $tokenResponse->successful()) {
            throw new RuntimeException('Google no pudo completar el intercambio OAuth.');
        }

        $accessToken = $tokenResponse->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('Google no devolvió un token de acceso válido.');
        }

        $userResponse = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(15)
            ->get('https://openidconnect.googleapis.com/v1/userinfo');

        if (! $userResponse->successful()) {
            throw new RuntimeException('Google no pudo devolver la identidad del usuario.');
        }

        $sub = $userResponse->json('sub');
        $email = $userResponse->json('email');
        $emailVerified = $userResponse->json('email_verified');
        $name = $userResponse->json('name');
        $avatar = $userResponse->json('picture');

        if (! is_string($sub) || $sub === '' || ! is_string($email) || $email === '' || $emailVerified !== true) {
            throw new RuntimeException('Google no confirmó un correo electrónico verificado.');
        }

        return [
            'sub' => $sub,
            'email' => strtolower($email),
            'name' => is_string($name) && trim($name) !== '' ? trim($name) : strtolower($email),
            'avatar' => is_string($avatar) && $avatar !== '' ? $avatar : null,
        ];
    }

    public function frontendCallbackUrl(): string
    {
        $this->assertConfigured();

        return (string) config('services.google_oauth.frontend_callback_url');
    }

    private function assertConfigured(): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('El acceso con Google no está configurado.');
        }
    }
}
