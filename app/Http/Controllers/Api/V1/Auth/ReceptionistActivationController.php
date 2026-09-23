<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\CompleteReceptionistActivation;
use App\Actions\Auth\StartMobileSession;
use App\Actions\Auth\StartReceptionistActivation;
use App\Actions\Auth\VerifyOtpChallenge;
use App\Enums\AuthenticationClient;
use App\Enums\AuthenticationContext;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\StartReceptionistActivationRequest;
use App\Http\Requests\Api\V1\Auth\VerifyReceptionistActivationRequest;
use App\Http\Resources\UserResource;
use App\Models\OtpChallenge;
use Illuminate\Http\JsonResponse;

class ReceptionistActivationController extends Controller
{
    public function request(
        StartReceptionistActivationRequest $request,
        StartReceptionistActivation $startActivation,
    ): JsonResponse {
        $data = $request->validated();
        $challenge = $startActivation->handle($data['invitation_token'], $data['device_name'], $data['device_id']);

        return response()->json([
            'success' => true,
            'data' => [
                'challenge_id' => $challenge->id,
                'next_step' => 'verify_otp',
                'expires_in' => max(0, (int) ceil(now()->diffInSeconds($challenge->expires_at))),
                'resend_after' => config('authentication.otp.resend_seconds'),
                'resends_remaining' => max(0, config('authentication.otp.max_resends') - $challenge->resend_count),
            ],
        ], 202);
    }

    public function verify(
        VerifyReceptionistActivationRequest $request,
        StartReceptionistActivation $startActivation,
        VerifyOtpChallenge $verifyOtpChallenge,
        CompleteReceptionistActivation $completeActivation,
        StartMobileSession $startMobileSession,
    ): JsonResponse {
        $data = $request->validated();
        $startActivation->assertChallengeBelongsToInvitation($data['invitation_token'], $data['challenge_id']);
        $user = $verifyOtpChallenge->handle(
            $data['challenge_id'],
            $data['code'],
            OtpPurpose::Registration,
            AuthenticationClient::Mobile,
            $data['device_name'],
            $data['device_id'],
        );
        $user = $completeActivation->handle($data['invitation_token'], $user, $data['password']);
        $challenge = OtpChallenge::query()->findOrFail($data['challenge_id']);
        $token = $startMobileSession->handle(
            $user,
            AuthenticationContext::Receptionist,
            (string) $challenge->device_name,
            (string) $challenge->device_id_hash,
        );
        $request->attributes->set('actor_context', AuthenticationContext::Receptionist->value);

        return response()->json([
            'success' => true,
            'data' => [
                'token_type' => 'Bearer',
                'access_token' => $token->plainTextToken,
                'expires_at' => $token->accessToken->expires_at,
                'user' => new UserResource($user),
                'actor_context' => AuthenticationContext::Receptionist->value,
                'next_step' => 'success',
            ],
        ]);
    }
}
