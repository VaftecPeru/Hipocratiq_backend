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
                'actor_context' => $request->attributes->get('actor_context'),
            ],
        ]);
    }

    public function renew(
        Request $request,
        AuthenticationSessionKey $sessionKey,
        RecordAuthenticationEvent $recordEvent,
    ): JsonResponse {
        if ($request->user()->currentAccessToken() instanceof PersonalAccessToken || ! $request->hasSession()) {
            return response()->json([
                'success' => false,
                'code' => 'WEB_SESSION_REQUIRED',
                'message' => 'Esta operación requiere una sesión web.',
            ], 400);
        }

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
        $user = $request->user();
        $accessToken = $user->currentAccessToken();

        if ($accessToken instanceof PersonalAccessToken) {
            $tokenId = $accessToken->id;
            $accessToken->delete();
            Auth::forgetGuards();
            $recordEvent->handleSafely($user, 'mobile_logout', metadata: [
                'token_id' => $tokenId,
            ]);

            return response()->noContent();
        }

        AuthenticationSession::query()
            ->whereBelongsTo($user)
            ->where('session_id_hash', $sessionKey->make($request->session()->getId()))
            ->update([
                'revoked_at' => now(),
                'revoked_reason' => 'logout',
            ]);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $recordEvent->handleSafely($user, 'logout');

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

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $recordEvent->handleSafely($user, 'logout_all');

        return response()->noContent();
    }
}
