<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DoctorProfileUpdateRequest;
use App\Models\DoctorProfile;
use App\Models\Specialty;
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

            $profileData = array_intersect_key($data, array_flip(['medical_license', 'specialty', 'specialty_id', 'subspecialty_id']));
            if (array_key_exists('specialty_id', $profileData) && $profileData['specialty_id'] !== null) {
                $profileData['specialty'] = Specialty::query()->findOrFail($profileData['specialty_id'])->name;
            }
            $profile->fill($profileData)->save();

            return $profile->fresh(['user', 'clinic', 'specialtyCatalog', 'subspecialtyCatalog']);
        });

        return response()->json(['success' => true, 'data' => $this->serialize($profile)]);
    }

    private function profile(Request $request, bool $lockForUpdate = false): DoctorProfile
    {
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
            'specialty' => $profile->specialty,
            'specialty_id' => $profile->specialty_id,
            'subspecialty_id' => $profile->subspecialty_id,
            'specialty_catalog' => $profile->specialtyCatalog?->only(['id', 'code', 'name']),
            'subspecialty_catalog' => $profile->subspecialtyCatalog?->only(['id', 'code', 'name']),
            'practice_type' => $profile->practice_type->value,
            'clinic' => $profile->clinic?->only(['id', 'name']),
        ];
    }
}
