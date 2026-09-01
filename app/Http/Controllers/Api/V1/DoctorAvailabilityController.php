<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Clinical\DoctorClinicScope;
use App\Support\Clinical\DoctorScheduling;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DoctorAvailabilityController extends Controller
{
    public function show(Request $request, DoctorScheduling $scheduling): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);
        $profile = DoctorClinicScope::activeProfile($request->user());
        $date = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $validated['date'],
            (string) config('app.timezone', 'UTC'),
        );

        return response()->json([
            'success' => true,
            'data' => $scheduling->availability($profile, $profile->clinic, $date),
        ]);
    }
}
