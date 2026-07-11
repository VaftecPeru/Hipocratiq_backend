<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\RegisterPatient;
use App\Actions\Auth\StartAuthenticatedSession;
use App\Actions\Auth\VerifyOtpChallenge;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RegisterPatientRequest;
use App\Http\Requests\Api\V1\Auth\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;

class PatientRegistrationController extends Controller
{
    public function store(RegisterPatientRequest $request, RegisterPatient $registerPatient): JsonResponse
    {
        $challenge = $registerPatient->handle($request->validated());

        return response()->json([
            'success' => true,
            'data' => [
                'challenge_id' => $challenge->id,
                'next_step' => 'verify_phone',
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
            OtpPurpose::Registration,
        );

        $startSession->handle($request, $user);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($user),
                'next_step' => 'select_plan',
            ],
        ]);
    }
}
