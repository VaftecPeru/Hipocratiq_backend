<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DoctorPreferencesUpdateRequest;
use App\Models\DoctorProfile;
use App\Support\Clinical\DoctorClinicScope;
use App\Support\Clinical\DoctorPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DoctorPreferencesController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $profile = DoctorClinicScope::activeProfile($request->user());

        return response()->json([
            'success' => true,
            'data' => DoctorPreferences::resolve($profile->preferences),
        ]);
    }

    public function update(DoctorPreferencesUpdateRequest $request): JsonResponse
    {
        $profile = DB::transaction(function () use ($request): DoctorProfile {
            $profile = DoctorClinicScope::activeProfile($request->user());
            $profile = DoctorProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $preferences = array_replace_recursive(
                DoctorPreferences::resolve($profile->preferences),
                $request->validated('preferences'),
            );
            $profile->update(['preferences' => $preferences]);

            return $profile->fresh();
        });

        return response()->json([
            'success' => true,
            'data' => DoctorPreferences::resolve($profile->preferences),
        ]);
    }
}
