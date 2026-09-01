<?php

use App\Http\Controllers\Api\V1\AdminAccessControlController;
use App\Http\Controllers\Api\V1\AdminAuditController;
use App\Http\Controllers\Api\V1\AdminBillingController;
use App\Http\Controllers\Api\V1\AdminClinicController;
use App\Http\Controllers\Api\V1\AdminDashboardController;
use App\Http\Controllers\Api\V1\AdminEmergencyController;
use App\Http\Controllers\Api\V1\AdminInfrastructureController;
use App\Http\Controllers\Api\V1\AdminMembershipController;
use App\Http\Controllers\Api\V1\AdminNotificationController;
use App\Http\Controllers\Api\V1\AdminPlatformSettingsController;
use App\Http\Controllers\Api\V1\AdminSupportTicketController;
use App\Http\Controllers\Api\V1\AdminSystemIncidentController;
use App\Http\Controllers\Api\V1\AdminUserController;
use App\Http\Controllers\Api\V1\Auth\ClinicRegistrationController;
use App\Http\Controllers\Api\V1\Auth\DoctorRegistrationController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\OtpAuthenticationController;
use App\Http\Controllers\Api\V1\Auth\OtpController;
use App\Http\Controllers\Api\V1\Auth\PatientRegistrationController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\ClinicDashboardController;
use App\Http\Controllers\Api\V1\DoctorAppointmentController;
use App\Http\Controllers\Api\V1\DoctorAvailabilityController;
use App\Http\Controllers\Api\V1\DoctorConsultationController;
use App\Http\Controllers\Api\V1\DoctorDashboardController;
use App\Http\Controllers\Api\V1\DoctorPatientController;
use App\Http\Controllers\Api\V1\DoctorPreferencesController;
use App\Http\Controllers\Api\V1\DoctorProfileController;
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
        Route::get('/specialties', [DoctorRegistrationController::class, 'specialties'])->name('specialties');
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
    Route::get('/platform-settings', [AdminPlatformSettingsController::class, 'publicShow'])
        ->name('platform-settings.show');

    Route::middleware(['auth:sanctum', 'auth.session'])->group(function (): void {
        Route::get('/me', [SessionController::class, 'show'])->name('me');
        Route::post('/session/renew', [SessionController::class, 'renew'])->name('session.renew');
        Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
        Route::post('/logout-all', [SessionController::class, 'destroyAll'])->name('logout-all');
        Route::get('/doctor/profile', [DoctorProfileController::class, 'show'])
            ->middleware('actor.context:doctor')
            ->name('doctor.profile.show');
        Route::patch('/doctor/profile', [DoctorProfileController::class, 'update'])
            ->middleware('actor.context:doctor')
            ->name('doctor.profile.update');
        Route::get('/doctor/dashboard', [DoctorDashboardController::class, 'show'])
            ->middleware('actor.context:doctor')
            ->name('doctor.dashboard.show');
        Route::get('/doctor/preferences', [DoctorPreferencesController::class, 'show'])
            ->middleware('actor.context:doctor')
            ->name('doctor.preferences.show');
        Route::patch('/doctor/preferences', [DoctorPreferencesController::class, 'update'])
            ->middleware('actor.context:doctor')
            ->name('doctor.preferences.update');
        Route::get('/doctor/availability', [DoctorAvailabilityController::class, 'show'])
            ->middleware('actor.context:doctor')
            ->name('doctor.availability.show');
        Route::get('/doctor/patients', [DoctorPatientController::class, 'patients'])
            ->middleware('actor.context:doctor')
            ->name('doctor.patients.index');
        Route::post('/doctor/patients', [DoctorPatientController::class, 'store'])
            ->middleware('actor.context:doctor')
            ->name('doctor.patients.store');
        Route::get('/doctor/patients/{patient}', [DoctorPatientController::class, 'show'])
            ->middleware('actor.context:doctor')
            ->name('doctor.patients.show');
        Route::patch('/doctor/patients/{patient}', [DoctorPatientController::class, 'update'])
            ->middleware('actor.context:doctor')
            ->name('doctor.patients.update');
        Route::get('/doctor/patients/{patient}/clinical-history', [DoctorPatientController::class, 'clinicalHistory'])
            ->middleware('actor.context:doctor')
            ->name('doctor.patients.clinical-history');
        Route::get('/doctor/appointments', [DoctorAppointmentController::class, 'index'])
            ->middleware('actor.context:doctor')
            ->name('doctor.appointments.index');
        Route::post('/doctor/appointments', [DoctorAppointmentController::class, 'store'])
            ->middleware('actor.context:doctor')
            ->name('doctor.appointments.store');
        Route::patch('/doctor/appointments/{appointment}', [DoctorAppointmentController::class, 'update'])
            ->middleware('actor.context:doctor')
            ->name('doctor.appointments.update');
        Route::patch('/doctor/appointments/{appointment}/status', [DoctorAppointmentController::class, 'updateStatus'])
            ->middleware('actor.context:doctor')
            ->name('doctor.appointments.status.update');
        Route::post('/doctor/appointments/{appointment}/cancel', [DoctorAppointmentController::class, 'cancel'])
            ->middleware('actor.context:doctor')
            ->name('doctor.appointments.cancel');
        Route::post('/doctor/patients/{patient}/consultations', [DoctorConsultationController::class, 'store'])
            ->middleware('actor.context:doctor')
            ->name('doctor.consultations.store');
        Route::get('/doctor/consultations', [DoctorConsultationController::class, 'index'])
            ->middleware('actor.context:doctor')
            ->name('doctor.consultations.index');
        Route::get('/doctor/consultations/{consultation}', [DoctorConsultationController::class, 'show'])
            ->middleware('actor.context:doctor')
            ->name('doctor.consultations.show');
        Route::patch('/doctor/consultations/{consultation}', [DoctorConsultationController::class, 'update'])
            ->middleware('actor.context:doctor')
            ->name('doctor.consultations.update');

        Route::get('/clinic/dashboard', [ClinicDashboardController::class, 'show'])
            ->middleware(['actor.context:clinic_admin,receptionist', 'clinic.access'])
            ->name('clinic.dashboard');
        Route::get('/clinic/members', [ClinicDashboardController::class, 'members'])
            ->middleware(['actor.context:clinic_admin,receptionist', 'clinic.access'])
            ->name('clinic.members.index');
        Route::post('/clinic/members', [ClinicDashboardController::class, 'storeMember'])
            ->middleware(['actor.context:clinic_admin,receptionist', 'clinic.access', 'can:clinic.members.manage'])
            ->name('clinic.members.store');
        Route::post('/clinic/members/doctors', [ClinicDashboardController::class, 'storeDoctor'])
            ->middleware(['actor.context:clinic_admin,receptionist', 'clinic.access', 'can:clinic.members.manage'])
            ->name('clinic.members.doctors.store');
        Route::patch('/clinic/members/{member}', [ClinicDashboardController::class, 'updateMember'])
            ->middleware(['actor.context:clinic_admin,receptionist', 'clinic.access', 'can:clinic.members.manage'])
            ->name('clinic.members.update');
        Route::patch('/clinic/members/{member}/status', [ClinicDashboardController::class, 'updateMemberStatus'])
            ->middleware(['actor.context:clinic_admin,receptionist', 'clinic.access', 'can:clinic.members.manage'])
            ->name('clinic.members.status.update');
        Route::delete('/clinic/members/{member}', [ClinicDashboardController::class, 'destroyMember'])
            ->middleware(['actor.context:clinic_admin,receptionist', 'clinic.access', 'can:clinic.members.manage'])
            ->name('clinic.members.destroy');
        Route::get('/clinic/patients', [ClinicDashboardController::class, 'patients'])
            ->middleware(['actor.context:clinic_admin,receptionist', 'clinic.access', 'can:clinic.patients.manage'])
            ->name('clinic.patients.index');
        Route::post('/clinic/patients', [ClinicDashboardController::class, 'storePatient'])
            ->middleware(['actor.context:clinic_admin,receptionist', 'clinic.access', 'can:clinic.patients.manage'])
            ->name('clinic.patients.store');
        Route::get('/clinic/patients/export', [ClinicDashboardController::class, 'exportPatients'])
            ->middleware(['actor.context:clinic_admin,receptionist', 'clinic.access', 'can:clinic.patients.manage'])
            ->name('clinic.patients.export');
        Route::get('/clinic/patients/{patient}', [ClinicDashboardController::class, 'patient'])
            ->middleware(['actor.context:clinic_admin,receptionist', 'clinic.access', 'can:clinic.patients.manage'])
            ->name('clinic.patients.show');
        Route::patch('/clinic/patients/{patient}', [ClinicDashboardController::class, 'updatePatient'])
            ->middleware(['actor.context:clinic_admin,receptionist', 'clinic.access', 'can:clinic.patients.manage'])
            ->name('clinic.patients.update');
        Route::patch('/clinic/patients/{patient}/status', [ClinicDashboardController::class, 'updatePatientStatus'])
            ->middleware(['actor.context:clinic_admin,receptionist', 'clinic.access', 'can:clinic.patients.manage'])
            ->name('clinic.patients.status.update');
        Route::get('/clinic/appointments', [ClinicDashboardController::class, 'appointments'])
            ->middleware(['actor.context:clinic_admin,receptionist', 'clinic.access'])
            ->name('clinic.appointments.index');
        Route::post('/clinic/appointments', [ClinicDashboardController::class, 'storeAppointment'])
            ->middleware(['actor.context:clinic_admin,receptionist', 'clinic.access'])
            ->name('clinic.appointments.store');
        Route::patch('/clinic/appointments/{appointment}', [ClinicDashboardController::class, 'updateAppointment'])
            ->middleware(['actor.context:clinic_admin,receptionist', 'clinic.access'])
            ->name('clinic.appointments.update');
        Route::patch('/clinic/appointments/{appointment}/status', [ClinicDashboardController::class, 'updateAppointmentStatus'])
            ->middleware(['actor.context:clinic_admin,receptionist', 'clinic.access'])
            ->name('clinic.appointments.status.update');
        Route::get('/clinic/settings', [ClinicDashboardController::class, 'settings'])
            ->middleware(['actor.context:clinic_admin,receptionist', 'clinic.access'])
            ->name('clinic.settings.show');
        Route::patch('/clinic/settings', [ClinicDashboardController::class, 'updateSettings'])
            ->middleware(['actor.context:clinic_admin,receptionist', 'clinic.access', 'can:clinic.settings.manage'])
            ->name('clinic.settings.update');
        Route::get('/admin/clinics', [AdminClinicController::class, 'index'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.clinics.index');
        Route::post('/admin/clinics', [AdminClinicController::class, 'store'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.clinics.store');
        Route::get('/admin/dashboard', [AdminDashboardController::class, 'show'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.dashboard.show');
        Route::get('/admin/billing', [AdminBillingController::class, 'index'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.billing.index');
        Route::get('/admin/billing/export', [AdminBillingController::class, 'export'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.billing.export');
        Route::post('/admin/billing/manual-payments', [AdminBillingController::class, 'storeManualPayment'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.billing.manual-payments.store');
        Route::post('/admin/billing/payments/{payment}/refund', [AdminBillingController::class, 'refundManualPayment'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.billing.payments.refund');
        Route::get('/admin/reports/export', [AdminBillingController::class, 'reportExport'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.reports.export');
        Route::get('/admin/monitoring', [AdminDashboardController::class, 'monitoring'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.monitoring.show');
        Route::get('/admin/notifications', [AdminNotificationController::class, 'index'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.notifications.index');
        Route::post('/admin/notifications/read', [AdminNotificationController::class, 'read'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.notifications.read');
        Route::post('/admin/notifications/read-all', [AdminNotificationController::class, 'readAll'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.notifications.read-all');
        Route::post('/admin/system-incidents', [AdminSystemIncidentController::class, 'store'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.system-incidents.store');
        Route::patch('/admin/system-incidents/{systemIncident}', [AdminSystemIncidentController::class, 'update'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.system-incidents.update');
        Route::get('/admin/emergency', [AdminEmergencyController::class, 'show'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.emergency.show');
        Route::patch('/admin/emergency', [AdminEmergencyController::class, 'update'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.emergency.update');
        Route::post('/admin/emergency/publish', [AdminEmergencyController::class, 'publish'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.emergency.publish');
        Route::patch('/admin/clinics/{clinic}', [AdminClinicController::class, 'update'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.clinics.update');
        Route::get('/admin/audit-events', [AdminAuditController::class, 'index'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.audit-events.index');
        Route::get('/admin/users', [AdminUserController::class, 'index'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.users.index');
        Route::post('/admin/users', [AdminUserController::class, 'store'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.users.store');
        Route::get('/admin/access-control', [AdminAccessControlController::class, 'index'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.access-control.index');
        Route::patch('/admin/users/{user}/access', [AdminAccessControlController::class, 'update'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.users.access.update');
        Route::patch('/admin/users/{user}', [AdminUserController::class, 'update'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.users.update');
        Route::post('/admin/users/{user}/messages', [AdminUserController::class, 'message'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.users.messages.store');
        Route::get('/admin/platform-settings', [AdminPlatformSettingsController::class, 'show'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.platform-settings.show');
        Route::patch('/admin/platform-settings', [AdminPlatformSettingsController::class, 'update'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.platform-settings.update');
        Route::get('/admin/infrastructure', [AdminInfrastructureController::class, 'show'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.infrastructure.show');
        Route::get('/admin/support-tickets', [AdminSupportTicketController::class, 'index'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.support-tickets.index');
        Route::post('/admin/support-tickets', [AdminSupportTicketController::class, 'store'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.support-tickets.store');
        Route::get('/admin/support-tickets/{supportTicket}', [AdminSupportTicketController::class, 'show'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.support-tickets.show');
        Route::patch('/admin/support-tickets/{supportTicket}', [AdminSupportTicketController::class, 'update'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.support-tickets.update');
        Route::post('/admin/support-tickets/{supportTicket}/comments', [AdminSupportTicketController::class, 'comment'])
            ->middleware(['actor.context:staff', 'can:manage-platform'])
            ->name('admin.support-tickets.comments.store');
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
