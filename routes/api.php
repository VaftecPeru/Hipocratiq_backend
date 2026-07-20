<?php

use App\Http\Controllers\Api\V1\AdminClinicController;
use App\Http\Controllers\Api\V1\Auth\ClinicRegistrationController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\OtpAuthenticationController;
use App\Http\Controllers\Api\V1\Auth\OtpController;
use App\Http\Controllers\Api\V1\Auth\PatientRegistrationController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\ClinicDashboardController;
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

Route::prefix('v1/auth')->name('api.v1.auth.')->group(function (): void {
    Route::prefix('register/clinic')->name('register.clinic.')->group(function (): void {
        Route::get('/plans', [ClinicRegistrationController::class, 'plans'])->name('plans');
        Route::post('/draft', [ClinicRegistrationController::class, 'draft'])->middleware('throttle:clinic-registration')->name('draft');
        Route::post('/plan', [ClinicRegistrationController::class, 'selectPlan'])->middleware('throttle:clinic-registration')->name('plan');
        Route::post('/verify-otp', [ClinicRegistrationController::class, 'verify'])->middleware('throttle:auth-otp')->name('verify-otp');
        Route::post('/resend-otp', [ClinicRegistrationController::class, 'resend'])->middleware('throttle:clinic-registration-otp')->name('resend-otp');
    });
    Route::post('/otp/request', [OtpAuthenticationController::class, 'request'])
        ->middleware('throttle:auth-otp-request')
        ->name('otp.request');
    Route::post('/otp/verify', [OtpAuthenticationController::class, 'verify'])
        ->middleware('throttle:auth-otp')
        ->name('otp.verify');
    Route::post('/register/patient', [PatientRegistrationController::class, 'store'])
        ->middleware('throttle:auth-register')
        ->name('register.patient');
    Route::post('/register/verify-otp', [PatientRegistrationController::class, 'verify'])
        ->middleware('throttle:auth-otp')
        ->name('register.verify-otp');
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:auth-login')
        ->name('login');
    Route::post('/login/verify-otp', [LoginController::class, 'verify'])
        ->middleware('throttle:auth-otp')
        ->name('login.verify-otp');
    Route::post('/otp/resend', [OtpController::class, 'store'])
        ->middleware('throttle:auth-otp')
        ->name('otp.resend');

    Route::middleware(['auth:sanctum', 'auth.session'])->group(function (): void {
        Route::get('/me', [SessionController::class, 'show'])->name('me');
        Route::post('/session/renew', [SessionController::class, 'renew'])->name('session.renew');
        Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
        Route::post('/logout-all', [SessionController::class, 'destroyAll'])->name('logout-all');

        Route::get('/clinic/dashboard', [ClinicDashboardController::class, 'show'])
            ->middleware('clinic.access')
            ->name('clinic.dashboard');
        Route::get('/admin/clinics', [AdminClinicController::class, 'index'])
            ->middleware('can:manage-platform')
            ->name('admin.clinics.index');
    });
});

Route::fallback(function (): JsonResponse {
    return response()->json([
        'success' => false,
        'message' => 'API endpoint not found.',
    ], 404);
})->name('api.fallback');
