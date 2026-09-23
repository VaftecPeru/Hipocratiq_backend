<?php

namespace App\Actions\Auth;

use App\Enums\AuthenticationContext;
use App\Models\AuthenticationSession;
use App\Models\User;
use App\Services\Auth\AuthenticationSessionKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class StartAuthenticatedSession
{
    public function __construct(
        private AuthenticationSessionKey $sessionKey,
        private RecordAuthenticationEvent $recordEvent,
    ) {}

    public function handle(
        Request $request,
        User $user,
        AuthenticationContext $context = AuthenticationContext::Staff,
    ): AuthenticationSession {
        if (! $request->hasSession()) {
            throw new BadRequestHttpException('Se requiere una solicitud stateful para iniciar sesión.');
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('actor_context', $context->value);
        $request->attributes->set('actor_context', $context->value);
        $user->update(['last_login_at' => now()]);

        $session = $user->authenticationSessions()->create([
            'id' => (string) Str::uuid(),
            'session_id_hash' => $this->sessionKey->make($request->session()->getId()),
            'actor_context' => $context->value,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'last_seen_at' => now(),
            'expires_at' => now()->addMinutes(config('authentication.session.absolute_minutes')),
        ]);

        $this->recordEvent->handle($user, 'login_succeeded');

        return $session;
    }
}
