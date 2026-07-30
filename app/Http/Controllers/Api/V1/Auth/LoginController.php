<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\StartAuthenticatedSession;
use App\Actions\Auth\StartLogin;
use App\Actions\Auth\VerifyOtpChallenge;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Models\OtpChallenge;
use Illuminate\Http\JsonResponse;

class LoginController extends Controller
{
    public function store(LoginRequest $request, StartLogin $startLogin): JsonResponse
    {
        $challenge = $startLogin->handle($request->validated());

        return response()->json([
            'success' => true,
            'data' => [
                'challenge_id' => $challenge->id,
                'next_step' => 'verify_otp',
                'expires_in' => max(0, (int) ceil(now()->diffInSeconds($challenge->expires_at))),
                'resend_after' => config('authentication.otp.resend_seconds'),
            ],
        ], 202);
    }

    public function verify(
        VerifyOtpRequest $request,
        VerifyOtpChallenge $verifyOtpChallenge,
        StartAuthenticatedSession $startSession,
    ): JsonResponse {
        $user = $verifyOtpChallenge->handle(
            $request->string('challenge_id')->toString(),
            $request->string('code')->toString(),
            OtpPurpose::Login,
        );
        $challenge = OtpChallenge::query()->findOrFail($request->string('challenge_id')->toString());

        $startSession->handle($request, $user, $challenge->actor_context);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($user),
                'actor_context' => $challenge->actor_context->value,
            ],
        ]);
    }
}
