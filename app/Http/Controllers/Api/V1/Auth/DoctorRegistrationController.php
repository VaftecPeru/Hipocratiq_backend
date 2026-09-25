<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\CompleteDoctorRegistration;
use App\Actions\Auth\StartAuthenticatedSession;
use App\Actions\Auth\StartMobileSession;
use App\Enums\AuthenticationClient;
use App\Enums\AuthenticationContext;
use App\Enums\DoctorPracticeType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\SelectClinicPlanRequest;
use App\Http\Requests\Api\V1\Auth\StartDoctorRegistrationRequest;
use App\Http\Requests\Api\V1\Auth\VerifyClinicRegistrationRequest;
use App\Http\Resources\UserResource;
use App\Models\Clinic;
use App\Models\Specialty;
use App\Services\Auth\DoctorRegistrationDraftStore;
use App\Services\Auth\GoogleRegistrationIdentity;
use App\Services\Auth\RegistrationDraftAccess;
use App\Support\Auth\MobileDeviceProof;
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
                ->whereHas('subscriptions', fn ($query) => $query->currentlyActive())
                ->orderBy('name')
                ->get(['id', 'name', 'country_code', 'address']),
        ]);
    }

    public function specialties(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => Specialty::query()
                ->whereNull('parent_id')
                ->where('is_active', true)
                ->with(['subspecialties' => fn ($query) => $query->where('is_active', true)->orderBy('name')])
                ->orderBy('name')
                ->get()
                ->map(fn (Specialty $specialty): array => [
                    'id' => $specialty->id,
                    'code' => $specialty->code,
                    'name' => $specialty->name,
                    'subspecialties' => $specialty->subspecialties->map(fn (Specialty $subspecialty): array => [
                        'id' => $subspecialty->id,
                        'code' => $subspecialty->code,
                        'name' => $subspecialty->name,
                    ])->values(),
                ])->values(),
        ]);
    }

    public function draft(
        StartDoctorRegistrationRequest $request,
        DoctorRegistrationDraftStore $store,
        GoogleRegistrationIdentity $googleIdentity,
    ): JsonResponse {
        $data = $request->validated();
        $identity = $googleIdentity->resolve(
            $data['google_registration_token'] ?? null,
            'doctor',
            $data['doctor_email'],
        );
        if ($identity !== null) {
            $data['google_identity'] = $identity;
        }
        $client = AuthenticationClient::from($data['client_type']);
        if (isset($data['specialty_id'])) {
            $data['specialty'] = Specialty::query()->findOrFail($data['specialty_id'])->name;
        }

        if ($data['practice_type'] === DoctorPracticeType::Clinic->value && ! Clinic::query()
            ->whereKey($data['clinic_id'])
            ->whereHas('subscriptions', fn ($query) => $query->currentlyActive())
            ->exists()) {
            throw ValidationException::withMessages(['clinic_id' => ['La clínica seleccionada no tiene un plan activo.']]);
        }

        $data['doctor_phone_number'] = PhoneNumber::from($data['doctor_phone_number'], $data['country_code'])->value;
        $data['password_hash'] = Hash::make($data['password']);
        if ($client === AuthenticationClient::Mobile) {
            $data['device_name'] = trim((string) $data['device_name']);
            $data['device_id_hash'] = MobileDeviceProof::hashId((string) $data['device_id']);
        }
        unset($data['password'], $data['password_confirmation'], $data['terms_accepted'], $data['device_id']);
        $token = $store->create($data);

        $responseData = ['next_step' => 'select_plan'];
        if ($data['practice_type'] === DoctorPracticeType::Clinic->value) {
            $responseData = [...$store->issueOtp($token), 'next_step' => 'verify_otp'];
        }

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
        DoctorRegistrationDraftStore $store,
        RegistrationDraftAccess $draftAccess,
    ): JsonResponse {
        $token = $draftAccess->token($request, 'doctor_registration');
        $draft = $store->get($token);
        $draftAccess->assertDevice($request, $draft);
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
        StartMobileSession $startMobileSession,
        RegistrationDraftAccess $draftAccess,
    ): JsonResponse {
        $token = $draftAccess->token($request, 'doctor_registration');
        $draftAccess->assertDevice($request, $store->get($token));
        $draft = $store->verifyOtp($token, $request->string('challenge_id')->toString(), $request->string('code')->toString());
        $user = $completeRegistration->handle($token, $draft);

        if ($draftAccess->isMobile($draft)) {
            $mobileToken = $startMobileSession->handle(
                $user,
                AuthenticationContext::Doctor,
                (string) $draft['data']['device_name'],
                (string) $draft['data']['device_id_hash'],
            );
            $request->attributes->set('actor_context', AuthenticationContext::Doctor->value);

            return response()->json([
                'success' => true,
                'data' => [
                    'token_type' => 'Bearer',
                    'access_token' => $mobileToken->plainTextToken,
                    'expires_at' => $mobileToken->accessToken->expires_at,
                    'user' => new UserResource($user),
                    'actor_context' => AuthenticationContext::Doctor->value,
                    'next_step' => 'success',
                ],
            ]);
        }

        $startSession->handle($request, $user, AuthenticationContext::Doctor);

        return response()->json([
            'success' => true,
            'data' => ['next_step' => 'success', 'user_id' => $user->id],
        ])->withoutCookie('doctor_registration');
    }

    public function resend(
        Request $request,
        DoctorRegistrationDraftStore $store,
        RegistrationDraftAccess $draftAccess,
    ): JsonResponse {
        $token = $draftAccess->token($request, 'doctor_registration');
        $draftAccess->assertDevice($request, $store->get($token));
        $challenge = $store->issueOtp($token, true);

        return response()->json(['success' => true, 'data' => [...$challenge, 'next_step' => 'verify_otp']]);
    }

    private function draftCookie(string $token): Cookie
    {
        return cookie('doctor_registration', $token, 30, '/', null, app()->isProduction(), true, false, 'lax');
    }
}
