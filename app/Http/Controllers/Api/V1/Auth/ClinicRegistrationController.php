<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\CompleteClinicRegistration;
use App\Actions\Auth\StartAuthenticatedSession;
use App\Enums\AuthenticationContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\SelectClinicPlanRequest;
use App\Http\Requests\Api\V1\Auth\StartClinicRegistrationRequest;
use App\Http\Requests\Api\V1\Auth\VerifyClinicRegistrationRequest;
use App\Models\Plan;
use App\Services\Auth\ClinicRegistrationDraftStore;
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
        $data['admin_phone_number'] = PhoneNumber::from($data['admin_phone_number'], $data['country_code'])->value;
        $data['contact_phone'] = PhoneNumber::from($data['contact_phone'], $data['country_code'])->value;
        $data['password_hash'] = Hash::make($data['password']);
        unset($data['password'], $data['password_confirmation'], $data['terms_accepted']);

        $token = $store->create($data);

        return response()->json([
            'success' => true,
            'data' => ['next_step' => 'select_plan'],
        ], 201)->cookie($this->draftCookie($token));
    }

    public function selectPlan(SelectClinicPlanRequest $request, ClinicRegistrationDraftStore $store): JsonResponse
    {
        $token = $this->token($request);
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
    ): JsonResponse {
        $token = $this->token($request);
        $draft = $store->verifyOtp($token, $request->string('challenge_id')->toString(), $request->string('code')->toString());
        $result = $completeRegistration->handle($token, $draft);
        $startSession->handle($request, $result['user'], AuthenticationContext::ClinicAdmin);

        return response()->json([
            'success' => true,
            'data' => ['next_step' => 'success', 'user_id' => $result['user']->id, 'clinic_id' => $result['clinic']->id],
        ])->withoutCookie('clinic_registration');
    }

    public function resend(Request $request, ClinicRegistrationDraftStore $store): JsonResponse
    {
        $challenge = $store->issueOtp($this->token($request));

        return response()->json(['success' => true, 'data' => [...$challenge, 'next_step' => 'verify_otp']]);
    }

    private function token(Request $request): string
    {
        $token = $request->cookie('clinic_registration');

        if (! is_string($token) || $token === '') {
            abort(422, 'El registro temporal no está disponible.');
        }

        return $token;
    }

    private function draftCookie(string $token): Cookie
    {
        return cookie('clinic_registration', $token, 30, '/', null, app()->isProduction(), true, false, 'lax');
    }
}
