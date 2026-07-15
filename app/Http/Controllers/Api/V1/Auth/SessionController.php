<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\RecordAuthenticationEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AuthenticationSession;
use App\Services\Auth\AuthenticationSessionKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class SessionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $accessToken = $request->user()->currentAccessToken();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($request->user()),
                'client_type' => $accessToken instanceof PersonalAccessToken ? 'mobile' : 'web',
                'actor_context' => $accessToken instanceof PersonalAccessToken
                    ? $accessToken->abilities[0] ?? null
                    : $request->session()->get('actor_context'),
            ],
        ]);
    }

    public function renew(
        Request $request,
        AuthenticationSessionKey $sessionKey,
        RecordAuthenticationEvent $recordEvent,
    ): JsonResponse {
        $currentHash = $sessionKey->make($request->session()->getId());
        $session = AuthenticationSession::query()
            ->whereBelongsTo($request->user())
            ->where('session_id_hash', $currentHash)
            ->whereNull('revoked_at')
            ->firstOrFail();

        $request->session()->regenerate();
        $session->update([
            'session_id_hash' => $sessionKey->make($request->session()->getId()),
            'last_seen_at' => now(),
        ]);
        $recordEvent->handle($request->user(), 'session_renewed');

        return response()->json(['success' => true]);
    }

    public function destroy(
        Request $request,
        AuthenticationSessionKey $sessionKey,
        RecordAuthenticationEvent $recordEvent,
    ): Response {
        $accessToken = $request->user()->currentAccessToken();

        if ($accessToken instanceof PersonalAccessToken) {
            $recordEvent->handle($request->user(), 'mobile_logout', metadata: [
                'token_id' => $accessToken->id,
            ]);
            $accessToken->delete();
            Auth::forgetGuards();

            return response()->noContent();
        }

        AuthenticationSession::query()
            ->whereBelongsTo($request->user())
            ->where('session_id_hash', $sessionKey->make($request->session()->getId()))
            ->update([
                'revoked_at' => now(),
                'revoked_reason' => 'logout',
            ]);

        $recordEvent->handle($request->user(), 'logout');
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function destroyAll(Request $request, RecordAuthenticationEvent $recordEvent): Response
    {
        $user = $request->user();

        $user->tokens()->delete();

        $user->authenticationSessions()
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoked_reason' => 'logout_all',
            ]);

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }

        $recordEvent->handle($user, 'logout_all');

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }
}
