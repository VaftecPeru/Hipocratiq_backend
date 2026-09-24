<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Admin\RecordAdminAuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AdminDoctorCredentialVerificationRequest;
use App\Models\DoctorCredentialVerification;
use App\Models\DoctorProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminDoctorCredentialController extends Controller
{
    public function show(Request $request, DoctorProfile $doctorProfile): JsonResponse
    {
        $doctorProfile->loadMissing(['user:id,name,email,country_code', 'specialty:id,name', 'subspecialty:id,name']);

        return response()->json([
            'success' => true,
            'data' => [
                'doctor' => $this->serializeProfile($doctorProfile),
                'history' => DoctorCredentialVerification::query()
                    ->with('verifiedBy:id,name,email')
                    ->where('doctor_profile_id', $doctorProfile->id)
                    ->latest('verified_at')
                    ->limit(50)
                    ->get()
                    ->map(fn (DoctorCredentialVerification $item): array => [
                        'id' => $item->id,
                        'credential_type' => $item->credential_type,
                        'credential_value' => $item->credential_value,
                        'status' => $item->status,
                        'reference' => $item->reference,
                        'notes' => $item->notes,
                        'verified_at' => $item->verified_at?->toIso8601String(),
                        'verified_by' => $item->verifiedBy?->only(['id', 'name', 'email']),
                    ])
                    ->values()
                    ->all(),
            ],
        ]);
    }

    public function store(
        AdminDoctorCredentialVerificationRequest $request,
        DoctorProfile $doctorProfile,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        $data = $request->validated();
        $actor = $request->user();
        $credentialType = $data['credential_type'];

        [$profile, $verification] = DB::transaction(function () use ($doctorProfile, $data, $actor, $credentialType): array {
            $profile = DoctorProfile::query()->lockForUpdate()->findOrFail($doctorProfile->id);
            $credentialValue = $credentialType === 'professional'
                ? $profile->medical_license
                : $profile->specialty_license;

            if (blank($credentialValue)) {
                throw ValidationException::withMessages([
                    'credential_type' => ['El médico no tiene registrada esta credencial.'],
                ]);
            }

            $verifiedAt = now();
            $verification = DoctorCredentialVerification::query()->create([
                'doctor_profile_id' => $profile->id,
                'credential_type' => $credentialType,
                'credential_value' => $credentialValue,
                'status' => $data['status'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'verified_by_user_id' => $actor->id,
                'verified_at' => $verifiedAt,
            ]);

            if ($credentialType === 'professional') {
                $profile->forceFill([
                    'medical_license_verification_status' => $data['status'],
                    'medical_license_verified_at' => $verifiedAt,
                    'medical_license_verification_reference' => $data['reference'] ?? null,
                ])->save();
            } else {
                $profile->forceFill([
                    'specialty_license_verification_status' => $data['status'],
                    'specialty_license_verified_at' => $verifiedAt,
                    'specialty_license_verification_reference' => $data['reference'] ?? null,
                ])->save();
            }

            return [$profile->fresh(), $verification];
        });

        $profile->loadMissing(['user:id,name,email,country_code', 'specialty:id,name', 'subspecialty:id,name']);

        $audit->handle(
            $actor,
            $request,
            'professional_credentials',
            'doctor_credential_'.$data['status'],
            DoctorProfile::class,
            $profile->id,
            $profile->user?->name,
            clinic: $profile->clinic,
            countryCode: $profile->user?->country_code,
            metadata: [
                'credential_type' => $credentialType,
                'credential_value' => $verification->credential_value,
                'reference' => $verification->reference,
            ],
            critical: true,
        );

        return response()->json([
            'success' => true,
            'data' => $this->serializeProfile($profile),
        ]);
    }

    /** @return array<string, mixed> */
    private function serializeProfile(DoctorProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'user' => $profile->user?->only(['id', 'name', 'email', 'country_code']),
            'medical_license' => $profile->medical_license,
            'medical_license_country_code' => $profile->medical_license_country_code,
            'medical_license_issuer' => $profile->medical_license_issuer,
            'medical_license_verification_status' => $profile->medical_license_verification_status,
            'medical_license_verified_at' => $profile->medical_license_verified_at?->toIso8601String(),
            'medical_license_verification_reference' => $profile->medical_license_verification_reference,
            'specialty_license' => $profile->specialty_license,
            'specialty_license_verification_status' => $profile->specialty_license_verification_status,
            'specialty_license_verified_at' => $profile->specialty_license_verified_at?->toIso8601String(),
            'specialty_license_verification_reference' => $profile->specialty_license_verification_reference,
            'specialty' => $profile->specialty?->name ?? $profile->specialty,
            'subspecialty' => $profile->subspecialty?->name,
        ];
    }
}
