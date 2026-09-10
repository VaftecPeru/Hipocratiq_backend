<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\RegisterPatient;
use App\Actions\Auth\ResetPasswordWithOtp;
use App\Actions\Auth\StartAuthenticatedSession;
use App\Actions\Auth\StartLogin;
use App\Actions\Auth\StartMobileSession;
use App\Actions\Auth\StartPasswordReset;
use App\Actions\Auth\VerifyOtpChallenge;
use App\Enums\AuthenticationClient;
use App\Enums\AuthenticationContext;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RequestOtpRequest;
use App\Http\Requests\Api\V1\Auth\VerifyAuthenticationOtpRequest;
use App\Http\Resources\UserResource;
use App\Models\OtpChallenge;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class OtpAuthenticationController extends Controller
{
    public function request(
        RequestOtpRequest $request,
        RegisterPatient $registerPatient,
        StartLogin $startLogin,
        StartPasswordReset $startPasswordReset,
    ): JsonResponse {
        $data = $request->validated();
        $purpose = OtpPurpose::from($data['purpose']);
        $challenge = match ($purpose) {
            OtpPurpose::Registration => $registerPatient->handle($data),
            OtpPurpose::Login => $startLogin->handle($data),
            OtpPurpose::PasswordReset => $startPasswordReset->handle($data),
        };

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
        VerifyAuthenticationOtpRequest $request,
        VerifyOtpChallenge $verifyOtpChallenge,
        ResetPasswordWithOtp $resetPassword,
        StartAuthenticatedSession $startWebSession,
        StartMobileSession $startMobileSession,
    ): JsonResponse {
        $data = $request->validated();
        $purpose = OtpPurpose::from($data['purpose']);
        $challenge = OtpChallenge::query()->find($data['challenge_id']);

        if ($challenge?->actor_context === AuthenticationContext::Receptionist
            && $purpose === OtpPurpose::Registration) {
            throw ValidationException::withMessages([
                'challenge_id' => ['La activación requiere una invitación válida.'],
            ]);
        }

        $user = $verifyOtpChallenge->handle(
            $data['challenge_id'],
            $data['code'],
            $purpose,
            AuthenticationClient::from($data['client_type']),
            $data['device_name'] ?? null,
            $data['device_id'] ?? null,
        );
        $challenge = OtpChallenge::query()->findOrFail($data['challenge_id']);

        if ($purpose === OtpPurpose::PasswordReset) {
            $resetPassword->handle($user, $data['password']);

            return response()->json([
                'success' => true,
                'data' => ['next_step' => 'login'],
            ]);
        }

        if ($challenge->client_type === AuthenticationClient::Mobile) {
            $token = $startMobileSession->handle(
                $user,
                $challenge->actor_context,
                (string) $challenge->device_name,
                (string) $challenge->device_id_hash,
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'token_type' => 'Bearer',
                    'access_token' => $token->plainTextToken,
                    'expires_at' => $token->accessToken->expires_at,
                    'user' => new UserResource($user),
                    'actor_context' => $challenge->actor_context->value,
                    'next_step' => $purpose === OtpPurpose::Registration ? 'select_plan' : null,
                ],
            ]);
        }

        $startWebSession->handle($request, $user, $challenge->actor_context);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($user),
                'actor_context' => $challenge->actor_context->value,
                'next_step' => $purpose === OtpPurpose::Registration ? 'select_plan' : null,
            ],
        ]);
    }
}
