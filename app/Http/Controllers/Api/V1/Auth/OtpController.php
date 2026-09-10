<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\ResendOtpChallenge;
use App\Enums\AuthenticationClient;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ResendOtpRequest;
use Illuminate\Http\JsonResponse;

class OtpController extends Controller
{
    public function store(ResendOtpRequest $request, ResendOtpChallenge $resendOtpChallenge): JsonResponse
    {
        $challenge = $resendOtpChallenge->handle(
            $request->string('challenge_id')->toString(),
            AuthenticationClient::from($request->string('client_type')->toString()),
            $request->input('device_name'),
            $request->input('device_id'),
        );

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
}
