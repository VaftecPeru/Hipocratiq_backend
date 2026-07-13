<?php

namespace App\Http\Middleware;

use App\Actions\Auth\RecordAuthenticationEvent;
use App\Enums\UserStatus;
use App\Models\AuthenticationSession;
use App\Services\Auth\AuthenticationSessionKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuthenticationSessionIsActive
{
    public function __construct(
        private AuthenticationSessionKey $sessionKey,
        private RecordAuthenticationEvent $recordEvent,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return response()->json([
                'success' => false,
                'code' => 'SESSION_REQUIRED',
                'message' => 'Se requiere una sesión válida.',
            ], 401);
        }

        $user = $request->user()->fresh();
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

        return $next($request);
    }
}
