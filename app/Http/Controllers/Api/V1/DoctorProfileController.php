<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DoctorProfileUpdateRequest;
use App\Models\DoctorProfile;
use App\Models\Specialty;
use App\Support\Clinical\DoctorClinicScope;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DoctorProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->serialize($this->profile($request)),
        ]);
    }

    public function update(DoctorProfileUpdateRequest $request): JsonResponse
    {
        $profile = DB::transaction(function () use ($request): DoctorProfile {
            $profile = $this->profile($request, true);
            $data = $request->validated();
            $specialtyId = array_key_exists('specialty_id', $data) ? $data['specialty_id'] : $profile->specialty_id;
            $subspecialtyId = array_key_exists('subspecialty_id', $data) ? $data['subspecialty_id'] : $profile->subspecialty_id;

            if ($subspecialtyId !== null && ($specialtyId === null || ! Specialty::query()
                ->whereKey($subspecialtyId)
                ->where('parent_id', $specialtyId)
                ->where('is_active', true)
                ->exists())) {
                throw ValidationException::withMessages(['subspecialty_id' => ['La subespecialidad no pertenece a la especialidad seleccionada.']]);
            }

            $userData = array_intersect_key($data, array_flip(['name', 'email', 'country_code']));
            if (array_key_exists('phone_number', $data)) {
                $countryCode = $userData['country_code'] ?? $profile->user->country_code;
                $userData['phone_number'] = PhoneNumber::from($data['phone_number'], $countryCode)->value;
            }
            if ($userData !== []) {
                $profile->user->fill($userData)->save();
            }

            $profileData = array_intersect_key($data, array_flip(['medical_license', 'specialty', 'specialty_id', 'subspecialty_id', 'specialty_license']));
            $medicalLicenseChanged = array_key_exists('medical_license', $profileData)
                && $profileData['medical_license'] !== $profile->medical_license;
            $specialtyLicenseChanged = array_key_exists('specialty_license', $profileData)
                && $profileData['specialty_license'] !== $profile->specialty_license;
            if (array_key_exists('specialty_id', $profileData) && $profileData['specialty_id'] !== null) {
                $profileData['specialty'] = Specialty::query()->findOrFail($profileData['specialty_id'])->name;
            }
            if ($medicalLicenseChanged) {
                $profileData['medical_license_country_code'] = $profile->user->country_code;
                $profileData['medical_license_issuer'] = $profile->user->country_code === 'MX' ? 'SEP-DGP' : null;
                $profileData['medical_license_verification_status'] = 'unverified';
                $profileData['medical_license_verified_at'] = null;
                $profileData['medical_license_verification_reference'] = null;
            }
            if ($specialtyLicenseChanged) {
                $profileData['specialty_license_verification_status'] = filled($profileData['specialty_license'] ?? null) ? 'unverified' : 'not_applicable';
                $profileData['specialty_license_verified_at'] = null;
                $profileData['specialty_license_verification_reference'] = null;
            }
            if (array_key_exists('medical_license', $profileData) && $profileData['medical_license'] !== $profile->medical_license) {
                $profileData['medical_license_verification_status'] = 'unverified';
                $profileData['medical_license_verified_at'] = null;
                $profileData['medical_license_verification_reference'] = null;
            }
            if (array_key_exists('specialty_license', $profileData) && $profileData['specialty_license'] !== $profile->specialty_license) {
                $profileData['specialty_license_verification_status'] = filled($profileData['specialty_license']) ? 'unverified' : 'not_applicable';
                $profileData['specialty_license_verified_at'] = null;
                $profileData['specialty_license_verification_reference'] = null;
            }
            $profile->fill($profileData)->save();

            return $profile->fresh(['user', 'clinic', 'specialtyCatalog', 'subspecialtyCatalog']);
        });

        return response()->json(['success' => true, 'data' => $this->serialize($profile)]);
    }

    private function profile(Request $request, bool $lockForUpdate = false): DoctorProfile
    {
        DoctorClinicScope::activeProfile($request->user());

        $query = $request->user()->doctorProfile()->with(['user', 'clinic', 'specialtyCatalog', 'subspecialtyCatalog']);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function serialize(DoctorProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'name' => $profile->user->name,
            'email' => $profile->user->email,
            'phone_number' => $profile->user->phone_number,
            'country_code' => $profile->user->country_code,
            'medical_license' => $profile->medical_license,
            'medical_license_country_code' => $profile->medical_license_country_code,
            'medical_license_issuer' => $profile->medical_license_issuer,
            'medical_license_verification_status' => $profile->medical_license_verification_status,
            'medical_license_verified_at' => $profile->medical_license_verified_at?->toIso8601String(),
            'medical_license_verification_reference' => $profile->medical_license_verification_reference,
            'specialty' => $profile->specialty,
            'specialty_id' => $profile->specialty_id,
            'subspecialty_id' => $profile->subspecialty_id,
            'specialty_license' => $profile->specialty_license,
            'specialty_license_verification_status' => $profile->specialty_license_verification_status,
            'specialty_license_verified_at' => $profile->specialty_license_verified_at?->toIso8601String(),
            'specialty_license_verification_reference' => $profile->specialty_license_verification_reference,
            'specialty_catalog' => $profile->specialtyCatalog?->only(['id', 'code', 'name']),
            'subspecialty_catalog' => $profile->subspecialtyCatalog?->only(['id', 'code', 'name']),
            'practice_type' => $profile->practice_type->value,
            'clinic' => $profile->clinic?->only(['id', 'name']),
        ];
    }
}
