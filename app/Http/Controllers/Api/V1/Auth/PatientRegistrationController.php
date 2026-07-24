<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\RegisterPatient;
use App\Actions\Auth\StartAuthenticatedSession;
use App\Actions\Auth\VerifyOtpChallenge;
use App\Enums\AuthenticationContext;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RegisterPatientRequest;
use App\Http\Requests\Api\V1\Auth\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Models\Clinic;
use Illuminate\Http\JsonResponse;

class PatientRegistrationController extends Controller
{
    public function clinics(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => Clinic::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'country_code', 'address']),
        ]);
    }

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
                'resends_remaining' => config('authentication.otp.max_resends'),
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

        $startSession->handle($request, $user, AuthenticationContext::Patient);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($user),
                'next_step' => 'select_plan',
                'plan_selection_deferred' => true,
            ],
        ]);
    }
}
