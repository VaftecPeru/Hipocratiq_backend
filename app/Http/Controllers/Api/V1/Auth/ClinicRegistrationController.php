<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\CompleteClinicRegistration;
use App\Actions\Auth\StartAuthenticatedSession;
use App\Actions\Auth\StartMobileSession;
use App\Enums\AuthenticationClient;
use App\Enums\AuthenticationContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\SelectClinicPlanRequest;
use App\Http\Requests\Api\V1\Auth\StartClinicRegistrationRequest;
use App\Http\Requests\Api\V1\Auth\VerifyClinicRegistrationRequest;
use App\Http\Resources\UserResource;
use App\Models\Plan;
use App\Services\Auth\ClinicRegistrationDraftStore;
use App\Services\Auth\RegistrationDraftAccess;
use App\Support\Auth\MobileDeviceProof;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Cookie;

class ClinicRegistrationController extends Controller
{
    public function plans(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => Plan::query()
                ->where('is_active', true)
                ->where('publicly_selectable', true)
                ->orderBy('monthly_price_cents')
                ->get(['key', 'name', 'monthly_price_cents', 'annual_price_cents', 'currency', 'features']),
        ]);
    }

    public function draft(StartClinicRegistrationRequest $request, ClinicRegistrationDraftStore $store): JsonResponse
    {
        $data = $request->validated();
        $client = AuthenticationClient::from($data['client_type']);
        $data['admin_phone_number'] = PhoneNumber::from($data['admin_phone_number'], $data['country_code'])->value;
        $data['contact_phone'] = PhoneNumber::from($data['contact_phone'], $data['country_code'])->value;
        $data['password_hash'] = Hash::make($data['password']);
        if ($client === AuthenticationClient::Mobile) {
            $data['device_name'] = trim((string) $data['device_name']);
            $data['device_id_hash'] = MobileDeviceProof::hashId((string) $data['device_id']);
        }
        unset($data['password'], $data['password_confirmation'], $data['terms_accepted'], $data['device_id']);

        $token = $store->create($data);

        $responseData = ['next_step' => 'select_plan'];
        if ($client === AuthenticationClient::Mobile) {
            $responseData['draft_token'] = $token;
        }

        $response = response()->json(['success' => true, 'data' => $responseData], 201);

        return $client === AuthenticationClient::Mobile
            ? $response
            : $response->cookie($this->draftCookie($token));
    }

    public function selectPlan(
        SelectClinicPlanRequest $request,
        ClinicRegistrationDraftStore $store,
        RegistrationDraftAccess $draftAccess,
    ): JsonResponse {
        $token = $draftAccess->token($request, 'clinic_registration');
        $draftAccess->assertDevice($request, $store->get($token));
        $data = $request->validated();
        $store->update($token, ['plan' => ['key' => $data['plan'], 'billing_cycle' => $data['billing_cycle']]]);
        $challenge = $store->issueOtp($token);

        return response()->json(['success' => true, 'data' => [...$challenge, 'next_step' => 'verify_otp']]);
    }

    public function verify(
        VerifyClinicRegistrationRequest $request,
        ClinicRegistrationDraftStore $store,
        CompleteClinicRegistration $completeRegistration,
        StartAuthenticatedSession $startSession,
        StartMobileSession $startMobileSession,
        RegistrationDraftAccess $draftAccess,
    ): JsonResponse {
        $token = $draftAccess->token($request, 'clinic_registration');
        $draftAccess->assertDevice($request, $store->get($token));
        $draft = $store->verifyOtp($token, $request->string('challenge_id')->toString(), $request->string('code')->toString());
        $result = $completeRegistration->handle($token, $draft);

        if ($draftAccess->isMobile($draft)) {
            $mobileToken = $startMobileSession->handle(
                $result['user'],
                AuthenticationContext::ClinicAdmin,
                (string) $draft['data']['device_name'],
                (string) $draft['data']['device_id_hash'],
            );
            $request->attributes->set('actor_context', AuthenticationContext::ClinicAdmin->value);

            return response()->json([
                'success' => true,
                'data' => [
                    'token_type' => 'Bearer',
                    'access_token' => $mobileToken->plainTextToken,
                    'expires_at' => $mobileToken->accessToken->expires_at,
                    'user' => new UserResource($result['user']),
                    'actor_context' => AuthenticationContext::ClinicAdmin->value,
                    'next_step' => 'success',
                    'clinic_id' => $result['clinic']->id,
                ],
            ]);
        }

        $startSession->handle($request, $result['user'], AuthenticationContext::ClinicAdmin);

        return response()->json([
            'success' => true,
            'data' => ['next_step' => 'success', 'user_id' => $result['user']->id, 'clinic_id' => $result['clinic']->id],
        ])->withoutCookie('clinic_registration');
    }

    public function resend(
        Request $request,
        ClinicRegistrationDraftStore $store,
        RegistrationDraftAccess $draftAccess,
    ): JsonResponse {
        $token = $draftAccess->token($request, 'clinic_registration');
        $draftAccess->assertDevice($request, $store->get($token));
        $challenge = $store->issueOtp($token, true);

        return response()->json(['success' => true, 'data' => [...$challenge, 'next_step' => 'verify_otp']]);
    }

    private function draftCookie(string $token): Cookie
    {
        return cookie('clinic_registration', $token, 30, '/', null, app()->isProduction(), true, false, 'lax');
    }
}
