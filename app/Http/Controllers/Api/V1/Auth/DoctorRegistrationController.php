<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\CompleteDoctorRegistration;
use App\Actions\Auth\StartAuthenticatedSession;
use App\Enums\AuthenticationContext;
use App\Enums\DoctorPracticeType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\SelectClinicPlanRequest;
use App\Http\Requests\Api\V1\Auth\StartDoctorRegistrationRequest;
use App\Http\Requests\Api\V1\Auth\VerifyClinicRegistrationRequest;
use App\Models\Clinic;
use App\Services\Auth\DoctorRegistrationDraftStore;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie;

class DoctorRegistrationController extends Controller
{
    public function clinics(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => Clinic::query()
                ->where('status', 'active')
                ->whereHas('subscriptions', fn ($query) => $query->where('status', 'active')->where(function ($query): void {
                    $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
                }))
                ->orderBy('name')
                ->get(['id', 'name', 'country_code', 'address']),
        ]);
    }

    public function draft(StartDoctorRegistrationRequest $request, DoctorRegistrationDraftStore $store): JsonResponse
    {
        $data = $request->validated();

        if ($data['practice_type'] === DoctorPracticeType::Clinic->value && ! Clinic::query()
            ->whereKey($data['clinic_id'])
            ->whereHas('subscriptions', fn ($query) => $query->where('status', 'active')->where(function ($query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
            }))
            ->exists()) {
            throw ValidationException::withMessages(['clinic_id' => ['La clínica seleccionada no tiene un plan activo.']]);
        }

        $data['doctor_phone_number'] = PhoneNumber::from($data['doctor_phone_number'], $data['country_code'])->value;
        $data['password_hash'] = Hash::make($data['password']);
        unset($data['password'], $data['password_confirmation'], $data['terms_accepted']);
        $token = $store->create($data);

        $responseData = ['next_step' => 'select_plan'];
        if ($data['practice_type'] === DoctorPracticeType::Clinic->value) {
            $responseData = [...$store->issueOtp($token), 'next_step' => 'verify_otp'];
        }

        return response()->json(['success' => true, 'data' => $responseData], 201)->cookie($this->draftCookie($token));
    }

    public function selectPlan(SelectClinicPlanRequest $request, DoctorRegistrationDraftStore $store): JsonResponse
    {
        $token = $this->token($request);
        $data = $request->validated();
        $store->selectPlan($token, ['key' => $data['plan'], 'billing_cycle' => $data['billing_cycle']]);
        $challenge = $store->issueOtp($token);

        return response()->json(['success' => true, 'data' => [...$challenge, 'next_step' => 'verify_otp']]);
    }

    public function verify(
        VerifyClinicRegistrationRequest $request,
        DoctorRegistrationDraftStore $store,
        CompleteDoctorRegistration $completeRegistration,
        StartAuthenticatedSession $startSession,
    ): JsonResponse {
        $token = $this->token($request);
        $draft = $store->verifyOtp($token, $request->string('challenge_id')->toString(), $request->string('code')->toString());
        $user = $completeRegistration->handle($token, $draft);
        $startSession->handle($request, $user, AuthenticationContext::Doctor);

        return response()->json([
            'success' => true,
            'data' => ['next_step' => 'success', 'user_id' => $user->id],
        ])->withoutCookie('doctor_registration');
    }

    public function resend(Request $request, DoctorRegistrationDraftStore $store): JsonResponse
    {
        $challenge = $store->issueOtp($this->token($request));

        return response()->json(['success' => true, 'data' => [...$challenge, 'next_step' => 'verify_otp']]);
    }

    private function token(Request $request): string
    {
        $token = $request->cookie('doctor_registration');

        if (! is_string($token) || $token === '') {
            abort(422, 'El registro temporal no está disponible.');
        }

        return $token;
    }

    private function draftCookie(string $token): Cookie
    {
        return cookie('doctor_registration', $token, 30, '/', null, app()->isProduction(), true, false, 'lax');
    }
}
