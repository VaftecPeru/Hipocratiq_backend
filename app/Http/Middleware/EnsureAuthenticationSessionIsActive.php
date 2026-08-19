<?php

namespace App\Http\Middleware;

use App\Actions\Auth\RecordAuthenticationEvent;
use App\Enums\AuthenticationContext;
use App\Enums\UserStatus;
use App\Models\AuthenticationSession;
use App\Services\Auth\AuthenticationSessionKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuthenticationSessionIsActive
{
    public function __construct(
        private AuthenticationSessionKey $sessionKey,
        private RecordAuthenticationEvent $recordEvent,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()->fresh();
        $accessToken = $request->user()->currentAccessToken();

        if ($accessToken instanceof PersonalAccessToken) {
            if ($user->status !== UserStatus::Active) {
                $accessToken->delete();
                $this->recordEvent->handle($user, 'mobile_token_revoked', metadata: [
                    'reason' => 'account_inactive',
                ]);

                return response()->json([
                    'success' => false,
                    'code' => 'ACCOUNT_INACTIVE',
                    'message' => 'La cuenta no está disponible.',
                ], 403);
            }

            $user->withAccessToken($accessToken);
            $request->setUserResolver(fn () => $user);
            $request->attributes->set('actor_context', $this->mobileActorContext($accessToken));

            return $next($request);
        }

        if (! $request->hasSession()) {
            return response()->json([
                'success' => false,
                'code' => 'SESSION_REQUIRED',
                'message' => 'Se requiere una sesión válida.',
            ], 401);
        }

        Auth::guard('web')->setUser($user);

        $session = AuthenticationSession::query()
            ->whereBelongsTo($user)
            ->where('session_id_hash', $this->sessionKey->make($request->session()->getId()))
            ->first();

        if ($session === null || $session->revoked_at !== null || $session->expires_at->isPast()) {
            if ($session !== null && $session->revoked_at === null) {
                $session->update([
                    'revoked_at' => now(),
                    'revoked_reason' => 'expired',
                ]);
                $this->recordEvent->handle($user, 'session_expired');
            }

            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return response()->json([
                'success' => false,
                'code' => 'SESSION_EXPIRED',
                'message' => 'La sesión ha expirado.',
            ], 401);
        }

        if ($user->status !== UserStatus::Active) {
            $session->update([
                'revoked_at' => now(),
                'revoked_reason' => 'account_inactive',
            ]);
            $this->recordEvent->handle($user, 'session_revoked', metadata: [
                'reason' => 'account_inactive',
            ]);
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return response()->json([
                'success' => false,
                'code' => 'ACCOUNT_INACTIVE',
                'message' => 'La cuenta no está disponible.',
            ], 403);
        }

        if ($session->last_seen_at->lt(now()->subMinutes(5))) {
            $session->update(['last_seen_at' => now()]);
        }

        $request->attributes->set('actor_context', $session->actor_context);

        return $next($request);
    }

    private function mobileActorContext(PersonalAccessToken $accessToken): ?string
    {
        foreach ($accessToken->abilities as $ability) {
            if (is_string($ability) && AuthenticationContext::tryFrom($ability) !== null) {
                return $ability;
            }
        }

        return null;
    }
}
