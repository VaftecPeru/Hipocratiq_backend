<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', function (): JsonResponse {
    return response()->json([
        'success' => true,
        'data' => [
            'name' => config('app.name'),
            'status' => 'ready',
            'message' => 'Hipocratiq backend API is running.',
        ],
    ]);
})->name('api.index');

Route::get('/health', function (): JsonResponse {
    return response()->json([
        'success' => true,
        'data' => [
            'status' => 'ok',
            'checked_at' => now()->toJSON(),
        ],
    ]);
})->name('api.health');

Route::fallback(function (): JsonResponse {
    return response()->json([
        'success' => false,
        'message' => 'API endpoint not found.',
    ], 404);
})->name('api.fallback');
