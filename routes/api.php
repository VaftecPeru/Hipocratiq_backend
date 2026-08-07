<?php

use App\Http\Controllers\Api\V1\AdminClinicController;
use App\Http\Controllers\Api\V1\AdminMembershipController;
use App\Http\Controllers\Api\V1\AdminUserController;
use App\Http\Controllers\Api\V1\Auth\ClinicRegistrationController;
use App\Http\Controllers\Api\V1\Auth\DoctorRegistrationController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\OtpAuthenticationController;
use App\Http\Controllers\Api\V1\Auth\OtpController;
use App\Http\Controllers\Api\V1\Auth\PatientRegistrationController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\ClinicDashboardController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Redis;
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
    $redisConnections = [];
    $cacheStores = [config('cache.default'), config('cache.limiter')];

    if (config('session.driver') === 'redis') {
        $cacheStores[] = config('session.store', 'redis_session');
        $redisConnections[] = config('session.connection', 'session');
    }

    foreach (array_unique(array_filter($cacheStores, 'is_string')) as $storeName) {
        $store = config('cache.stores.'.$storeName, []);

        if (($store['driver'] ?? null) === 'redis') {
            $redisConnections[] = $store['connection'] ?? 'default';
            $redisConnections[] = $store['lock_connection'] ?? $store['connection'] ?? 'default';
        }
    }

    $redisConnections = array_unique(array_filter($redisConnections, 'is_string'));
    $redisRequired = $redisConnections !== [];

    if ($redisRequired) {
        try {
            foreach (array_unique($redisConnections) as $connection) {
                Redis::connection((string) $connection)->ping();
            }
        } catch (Throwable) {
            return response()->json([
                'success' => false,
                'data' => [
                    'status' => 'unavailable',
                    'dependencies' => ['redis' => 'unavailable'],
                    'checked_at' => now()->toJSON(),
                ],
            ], 503);
        }
    }

    return response()->json([
        'success' => true,
        'data' => [
            'status' => 'ok',
            'dependencies' => [
                'redis' => $redisRequired ? 'ok' : 'not_required',
            ],
            'checked_at' => now()->toJSON(),
        ],
    ]);
})->name('api.health');

Route::prefix('v1/auth')->name('api.v1.auth.')->group(function (): void {
    Route::prefix('register/clinic')->name('register.clinic.')->group(function (): void {
        Route::get('/plans', [ClinicRegistrationController::class, 'plans'])->name('plans');
        Route::post('/draft', [ClinicRegistrationController::class, 'draft'])->middleware('throttle:clinic-registration')->name('draft');
        Route::post('/plan', [ClinicRegistrationController::class, 'selectPlan'])->middleware('throttle:registration-plan')->name('plan');
        Route::post('/verify-otp', [ClinicRegistrationController::class, 'verify'])->middleware('throttle:auth-otp')->name('verify-otp');
        Route::post('/resend-otp', [ClinicRegistrationController::class, 'resend'])->middleware('throttle:clinic-registration-otp')->name('resend-otp');
    });
    Route::prefix('register/doctor')->name('register.doctor.')->group(function (): void {
        Route::get('/clinics', [DoctorRegistrationController::class, 'clinics'])->name('clinics');
        Route::post('/draft', [DoctorRegistrationController::class, 'draft'])->middleware('throttle:clinic-registration')->name('draft');
        Route::post('/plan', [DoctorRegistrationController::class, 'selectPlan'])->middleware('throttle:registration-plan')->name('plan');
        Route::post('/verify-otp', [DoctorRegistrationController::class, 'verify'])->middleware('throttle:auth-otp')->name('verify-otp');
        Route::post('/resend-otp', [DoctorRegistrationController::class, 'resend'])->middleware('throttle:clinic-registration-otp')->name('resend-otp');
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
    Route::get('/register/patient/clinics', [PatientRegistrationController::class, 'clinics'])
        ->name('register.patient.clinics');
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
            ->middleware(['actor.context:clinic_admin', 'clinic.access'])
            ->name('clinic.dashboard');
        Route::get('/admin/clinics', [AdminClinicController::class, 'index'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.clinics.index');
        Route::get('/admin/users', [AdminUserController::class, 'index'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.users.index');
        Route::get('/admin/memberships', [AdminMembershipController::class, 'index'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.memberships.index');
        Route::post('/admin/memberships/plans', [AdminMembershipController::class, 'storePlan'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.memberships.plans.store');
        Route::patch('/admin/memberships/plans/{plan}', [AdminMembershipController::class, 'updatePlan'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.memberships.plans.update');
        Route::post('/admin/memberships/subscriptions', [AdminMembershipController::class, 'storeSubscription'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.memberships.subscriptions.store');
        Route::post('/admin/memberships/subscriptions/{subscription}/upgrade', [AdminMembershipController::class, 'upgradeSubscription'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.memberships.subscriptions.upgrade');
        Route::post('/admin/memberships/subscriptions/{subscription}/cancel', [AdminMembershipController::class, 'cancelSubscription'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.memberships.subscriptions.cancel');
    });
});

Route::fallback(function (): JsonResponse {
    return response()->json([
        'success' => false,
        'message' => 'API endpoint not found.',
    ], 404);
})->name('api.fallback');
