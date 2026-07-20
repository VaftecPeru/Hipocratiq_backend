<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClinicDashboardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $clinic = $request->attributes->get('clinic');
        $subscription = $clinic->subscriptions->first();

        return response()->json([
            'success' => true,
            'data' => [
                'clinic' => [
                    'id' => $clinic->id,
                    'name' => $clinic->name,
                    'status' => $clinic->status,
                ],
                'subscription' => [
                    'plan' => $subscription->plan->key,
                    'status' => $subscription->status,
                    'billing_cycle' => $subscription->billing_cycle,
                ],
            ],
        ]);
    }
}
