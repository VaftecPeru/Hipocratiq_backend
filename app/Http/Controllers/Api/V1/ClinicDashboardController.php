<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Admin\RecordAdminAuditEvent;
use App\Actions\Clinic\CreateClinicAppointment;
use App\Actions\Clinic\CreateClinicDoctor;
use App\Actions\Clinic\CreateClinicPatient;
use App\Actions\Clinic\CreateClinicReceptionist;
use App\Actions\Clinic\UpdateClinicPatient;
use App\Enums\AppointmentStatus;
use App\Enums\ClinicMembershipStatus;
use App\Enums\RoleKey;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ClinicAppointmentStatusUpdateRequest;
use App\Http\Requests\Api\V1\ClinicAppointmentStoreRequest;
use App\Http\Requests\Api\V1\ClinicAppointmentUpdateRequest;
use App\Http\Requests\Api\V1\ClinicDoctorStoreRequest;
use App\Http\Requests\Api\V1\ClinicMemberStatusUpdateRequest;
use App\Http\Requests\Api\V1\ClinicMemberStoreRequest;
use App\Http\Requests\Api\V1\ClinicMemberUpdateRequest;
use App\Http\Requests\Api\V1\ClinicPatientStatusUpdateRequest;
use App\Http\Requests\Api\V1\ClinicPatientStoreRequest;
use App\Http\Requests\Api\V1\ClinicPatientUpdateRequest;
use App\Http\Requests\Api\V1\ClinicSettingsUpdateRequest;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\PatientIntake;
use App\Models\PatientProfile;
use App\Models\User;
use App\Services\Notifications\ClinicalNotificationService;
use App\Support\Clinical\DoctorScheduling;
use App\Support\Clinical\EmergencyPolicyResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClinicDashboardController extends Controller
{
    /** @var array{maintenance: bool, audit: bool, signatures: bool, backups: bool} */
    private const DEFAULT_CLINIC_PREFERENCES = [
        'maintenance' => false,
        'audit' => true,
        'signatures' => true,
        'backups' => true,
    ];

    public function __construct(
        private readonly EmergencyPolicyResolver $emergencyPolicies,
        private readonly ClinicalNotificationService $clinicalNotifications,
        private readonly DoctorScheduling $doctorScheduling,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $clinic = $this->clinic($request);
        $subscription = $clinic->subscriptions->first();
        $members = $this->activeMembers($clinic);
        $patients = $this->recentPatients($clinic, 6);
        $doctors = $members->where('role.key', RoleKey::Doctor->value)->values();
        $receptionists = $members->where('role.key', RoleKey::Receptionist->value)->values();
        $todayAppointments = $this->appointmentsForDay($clinic, now());

        return response()->json([
            'success' => true,
            'data' => [
                'clinic' => $this->serializeClinic($clinic),
                'subscription' => [
                    'plan' => $subscription?->plan?->key,
                    'status' => $subscription?->status,
                    'billing_cycle' => $subscription?->billing_cycle,
                ],
                'metrics' => [
                    'staff_total' => $members->count(),
                    'doctors_total' => $doctors->count(),
                    'receptionists_total' => $receptionists->count(),
                    'patients_total' => $clinic->patientProfiles()->count(),
                ],
                'appointment_summary' => $this->appointmentSummary($todayAppointments),
                'today_appointments' => $todayAppointments
                    ->map(fn (Appointment $appointment): array => $this->serializeAppointment($appointment))
                    ->values(),
                'team' => $members->map(fn (ClinicMembership $membership): array => $this->serializeMember($membership))->values(),
                'recent_patients' => $patients->map(fn ($patient): array => $this->serializePatient($patient))->values(),
            ],
        ]);
    }

    public function members(Request $request): JsonResponse
    {
        $members = $this->activeMembers($this->clinic($request));

        return response()->json([
            'success' => true,
            'data' => $members->map(fn (ClinicMembership $membership): array => $this->serializeMember($membership))->values(),
        ]);
    }

    public function storeMember(
        ClinicMemberStoreRequest $request,
        CreateClinicReceptionist $createReceptionist,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        $clinic = $this->clinic($request);
        $user = $createReceptionist->handle($request->validated(), $clinic);

        $membership = $clinic->memberships()
            ->where('user_id', $user->id)
            ->with([
                'role:id,key,name',
                'user:id,name,email,phone_number,country_code,status,last_login_at',
                'user.doctorProfile:id,user_id,clinic_id,medical_license,specialty',
            ])
            ->firstOrFail();

        $audit->handle(
            $request->user(),
            $request,
            'clinic_team',
            'receptionist_created',
            User::class,
            $user->id,
            $user->name,
            clinic: $clinic,
            countryCode: $user->country_code,
            metadata: ['role' => RoleKey::Receptionist->value, 'requires_password_reset' => true],
        );

        return response()->json([
            'success' => true,
            'data' => $this->serializeMember($membership),
            'meta' => ['requires_password_reset' => true],
        ], 201);
    }

    public function patients(Request $request): JsonResponse
    {
        $clinic = $this->clinic($request);
        $query = $clinic->patientProfiles()
            ->with([
                'user:id,name,email,phone_number,status,created_at',
                'latestIntake.registrar:id,name',
                'lastAppointment.doctor:id,name',
                'lastAppointment.doctor.doctorProfile:id,user_id,specialty',
                'nextAppointment.doctor:id,name',
                'nextAppointment.doctor.doctorProfile:id,user_id,specialty',
            ])
            ->latest('id');
        $search = trim((string) $request->query('search', ''));

        if ($search !== '') {
            $patientCodeId = $this->patientCodeId($search);
            $query->where(function (Builder $patientQuery) use ($patientCodeId, $search): void {
                $patientQuery
                    ->where('document_number', 'like', "%{$search}%")
                    ->orWhereHas('user', function (Builder $userQuery) use ($search): void {
                        $userQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('phone_number', 'like', "%{$search}%");
                    });

                if ($patientCodeId !== null) {
                    $patientQuery->orWhere($patientQuery->getModel()->getQualifiedKeyName(), $patientCodeId);
                }
            });
        }

        $patients = $query->paginate(min(max((int) $request->query('per_page', 10), 1), 100));
        $summary = $this->patientSummary($clinic);

        return response()->json([
            'success' => true,
            'data' => $patients->getCollection()->map(fn ($patient): array => $this->serializePatient($patient))->values(),
            'meta' => [
                'current_page' => $patients->currentPage(),
                'from' => $patients->firstItem(),
                'last_page' => $patients->lastPage(),
                'per_page' => $patients->perPage(),
                'to' => $patients->lastItem(),
                'total' => $patients->total(),
                'summary' => $summary,
            ],
        ]);
    }

    public function storePatient(
        ClinicPatientStoreRequest $request,
        CreateClinicPatient $createPatient,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        $clinic = $this->clinic($request);
        $emergencyResolution = $request->validated('triage_priority') === 'emergency'
            ? $this->emergencyPolicies->resolve($clinic)
            : null;

        if ($emergencyResolution !== null && ! (bool) ($emergencyResolution['effective']['enabled'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'La política de emergencias no está habilitada para esta clínica.',
                'errors' => [
                    'triage_priority' => ['La admisión de emergencia está deshabilitada para esta clínica.'],
                ],
            ], 422);
        }

        $patient = $createPatient
            ->handle($request->validated(), $clinic, $request->user())
            ->load(['user', 'latestIntake.registrar:id,name']);

        if ($emergencyResolution !== null && $patient->latestIntake?->triage_priority === 'emergency') {
            $effective = $emergencyResolution['effective'];

            $audit->handle(
                $request->user(),
                $request,
                'emergency',
                'emergency_intake_created',
                PatientProfile::class,
                $patient->getKey(),
                $patient->user?->name,
                clinic: $clinic,
                countryCode: $clinic->country_code,
                metadata: [
                    'patient_intake_id' => $patient->latestIntake->getKey(),
                    'policy' => $effective,
                    'global_override' => $emergencyResolution['global_override'],
                    'responsibles' => $effective['responsibles'] ?? [],
                    'delivery' => ['in_app_audit' => true, 'external_channels' => false],
                ],
                critical: true,
            );
        }

        return response()->json([
            'success' => true,
            'data' => $this->serializePatient($patient),
        ], 201);
    }

    public function patient(Request $request, PatientProfile $patient): JsonResponse
    {
        $clinic = $this->clinic($request);
        $patient = $this->clinicPatient($clinic, $patient)->load([
            'user:id,name,email,phone_number,country_code,status,created_at',
            'latestIntake.registrar:id,name',
            'latestIntake.requestedDoctor:id,name',
            'latestIntake.requestedDoctor.doctorProfile:id,user_id,specialty',
            'latestIntake.specialty:id,name',
            'lastAppointment.doctor:id,name',
            'lastAppointment.doctor.doctorProfile:id,user_id,specialty',
            'nextAppointment.doctor:id,name',
            'nextAppointment.doctor.doctorProfile:id,user_id,specialty',
        ]);

        $recentAppointments = $this->patientAppointments($clinic, $patient)
            ->where('scheduled_at', '<=', now())
            ->latest('scheduled_at')
            ->limit(5)
            ->get();
        $nextAppointment = $this->patientAppointments($clinic, $patient)
            ->where('scheduled_at', '>', now())
            ->orderBy('scheduled_at')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'patient' => $this->serializePatient($patient),
                'profile' => $this->serializePatientProfile($patient),
                'intake' => $this->serializeIntakeSummary($patient->latestIntake),
                'recent_appointments' => $recentAppointments
                    ->map(fn (Appointment $appointment): array => $this->serializePatientAppointment($appointment))
                    ->values(),
                'next_appointment' => $nextAppointment === null ? null : $this->serializePatientAppointment($nextAppointment),
            ],
        ]);
    }

    public function updatePatient(
        ClinicPatientUpdateRequest $request,
        PatientProfile $patient,
        UpdateClinicPatient $updatePatient,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        $clinic = $this->clinic($request);
        $patient = $this->clinicPatient($clinic, $patient)->load('user');
        $before = $this->administrativePatientSnapshot($patient);
        $patient = $updatePatient->handle($request->validated(), $patient);
        $patient->load([
            'user:id,name,email,phone_number,country_code,status,created_at',
            'latestIntake.registrar:id,name',
            'lastAppointment.doctor:id,name',
            'lastAppointment.doctor.doctorProfile:id,user_id,specialty',
            'nextAppointment.doctor:id,name',
            'nextAppointment.doctor.doctorProfile:id,user_id,specialty',
        ]);
        $after = $this->administrativePatientSnapshot($patient);

        $audit->handle(
            $request->user(),
            $request,
            'clinic_patients',
            'patient_updated',
            PatientProfile::class,
            $patient->id,
            $patient->user?->name,
            clinic: $clinic,
            metadata: ['changed_fields' => $this->changedFields($before, $after)],
        );

        return response()->json(['success' => true, 'data' => $this->serializePatient($patient)]);
    }

    public function updatePatientStatus(
        ClinicPatientStatusUpdateRequest $request,
        PatientProfile $patient,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        $clinic = $this->clinic($request);
        $patient = $this->clinicPatient($clinic, $patient)->load('user');
        $user = $patient->user;

        if ($user === null) {
            abort(404);
        }

        $previousStatus = $user->status->value;
        $user->update(['status' => $request->validated('status')]);
        $patient->load([
            'user:id,name,email,phone_number,country_code,status,created_at',
            'latestIntake.registrar:id,name',
            'lastAppointment.doctor:id,name',
            'lastAppointment.doctor.doctorProfile:id,user_id,specialty',
            'nextAppointment.doctor:id,name',
            'nextAppointment.doctor.doctorProfile:id,user_id,specialty',
        ]);

        $audit->handle(
            $request->user(),
            $request,
            'clinic_patients',
            'patient_status_updated',
            PatientProfile::class,
            $patient->id,
            $patient->user?->name,
            clinic: $clinic,
            metadata: ['from' => $previousStatus, 'to' => $request->validated('status')],
        );

        return response()->json(['success' => true, 'data' => $this->serializePatient($patient)]);
    }

    public function exportPatients(Request $request, RecordAdminAuditEvent $audit): StreamedResponse
    {
        $clinic = $this->clinic($request);
        $audit->handle(
            $request->user(),
            $request,
            'clinic_patients',
            'patients_exported',
            clinic: $clinic,
        );

        return response()->streamDownload(function () use ($clinic): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Código', 'Paciente', 'Documento', 'Teléfono', 'Correo', 'Seguro', 'Paciente recurrente', 'WhatsApp', 'Estado', 'Registro']);

            foreach ($clinic->patientProfiles()
                ->select([
                    'id',
                    'user_id',
                    'clinic_id',
                    'document_type',
                    'document_number',
                    'insurance',
                    'is_returning_patient',
                    'whatsapp_consent',
                    'created_at',
                ])
                ->with('user:id,name,email,phone_number,status')
                ->orderBy('id')
                ->lazyById(100) as $patient) {
                $document = trim(implode(' ', array_filter([$patient->document_type, $patient->document_number])));
                fputcsv($output, [
                    $this->safeCsvValue('PAC-'.str_pad((string) $patient->id, 6, '0', STR_PAD_LEFT)),
                    $this->safeCsvValue($patient->user?->name),
                    $this->safeCsvValue($document),
                    $this->safeCsvValue($patient->user?->phone_number),
                    $this->safeCsvValue($patient->user?->email),
                    $this->safeCsvValue($patient->insurance),
                    $this->safeCsvValue($patient->is_returning_patient ? 'Sí' : 'No'),
                    $this->safeCsvValue($patient->whatsapp_consent ? 'Sí' : 'No'),
                    $this->safeCsvValue($patient->user?->status?->value),
                    $this->safeCsvValue($patient->created_at?->toDateString()),
                ]);
            }

            fclose($output);
        }, 'pacientes-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function appointments(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : now()->startOfWeek(Carbon::MONDAY);
        $to = isset($validated['to'])
            ? Carbon::parse($validated['to'])->endOfDay()
            : $from->copy()->addDays(6)->endOfDay();

        if ($to->lessThan($from)) {
            return response()->json([
                'success' => false,
                'message' => 'El rango de fechas no es válido.',
            ], 422);
        }

        $appointments = $this->appointmentsQuery($this->clinic($request))
            ->whereBetween('scheduled_at', [$from, $to])
            ->orderBy('scheduled_at')
            ->get();
        $summary = $this->appointmentSummary($appointments);
        $summary['today_total'] = $appointments
            ->filter(fn (Appointment $appointment): bool => $appointment->scheduled_at?->isToday() === true)
            ->count();
        $summary['period_total'] = $appointments->count();

        return response()->json([
            'success' => true,
            'data' => $appointments
                ->map(fn (Appointment $appointment): array => $this->serializeAppointment($appointment))
                ->values(),
            'meta' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'total' => $appointments->count(),
                'summary' => $summary,
            ],
        ]);
    }

    public function settings(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'clinic' => $this->serializeClinic($this->clinic($request)),
            ],
        ]);
    }

    public function updateSettings(
        ClinicSettingsUpdateRequest $request,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        $clinic = DB::transaction(function () use ($request, $audit): Clinic {
            $clinic = Clinic::query()->lockForUpdate()->findOrFail($this->clinic($request)->getKey());
            $validated = $request->validated();
            $before = $clinic->only(['name', 'contact_phone', 'address']);
            $before['preferences'] = $this->clinicPreferences($clinic);
            $beforeEmergency = $this->emergencyPolicies->localPolicy($clinic);

            if (is_array($validated['preferences'] ?? null)) {
                $incomingPreferences = $validated['preferences'];
                $storedPreferences = is_array($clinic->preferences) ? $clinic->preferences : [];
                $validated['preferences'] = array_replace(
                    self::DEFAULT_CLINIC_PREFERENCES,
                    $storedPreferences,
                    $incomingPreferences,
                );

                if (is_array($incomingPreferences['emergency'] ?? null)) {
                    $validated['preferences']['emergency'] = array_replace(
                        $this->emergencyPolicies->localPolicy($clinic),
                        $incomingPreferences['emergency'],
                    );
                }
            }

            $clinic->fill($validated)->save();
            $afterEmergency = $this->emergencyPolicies->localPolicy($clinic);

            $audit->handle(
                $request->user(),
                $request,
                'clinic_settings',
                'clinic_settings_updated',
                Clinic::class,
                $clinic->id,
                $clinic->name,
                clinic: $clinic,
                metadata: [
                    'before' => $before,
                    'after' => [
                        ...$clinic->only(['name', 'contact_phone', 'address']),
                        'preferences' => $this->clinicPreferences($clinic),
                    ],
                ],
            );

            if (! (bool) ($beforeEmergency['enabled'] ?? false)
                && (bool) ($afterEmergency['enabled'] ?? false)) {
                $resolution = $this->emergencyPolicies->resolve($clinic);

                if (! (bool) ($resolution['is_overridden'] ?? false)) {
                    $emergencyAudit = $audit->handle(
                        $request->user(),
                        $request,
                        'emergency',
                        'emergency_activated',
                        Clinic::class,
                        $clinic->getKey(),
                        $clinic->name,
                        clinic: $clinic,
                        countryCode: $clinic->country_code,
                        metadata: [
                            'policy' => $resolution['effective'],
                            'responsibles' => $resolution['effective']['responsibles'] ?? [],
                            'delivery' => ['in_app' => true, 'external_channels' => false],
                        ],
                        critical: true,
                    );
                    $notificationStats = $this->clinicalNotifications->createForEmergency(
                        [$clinic],
                        $resolution['effective'],
                        'emergency_activated',
                        'emergency_activation',
                        (string) $emergencyAudit->getKey(),
                    );
                    $emergencyAudit->update([
                        'metadata' => [
                            ...((array) $emergencyAudit->metadata),
                            'notifications' => $notificationStats,
                        ],
                    ]);
                }
            }

            return $clinic;
        });

        return response()->json([
            'success' => true,
            'data' => ['clinic' => $this->serializeClinic($clinic->fresh())],
        ]);
    }

    public function storeAppointment(
        ClinicAppointmentStoreRequest $request,
        CreateClinicAppointment $createAppointment,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        $clinic = $this->clinic($request);
        $appointment = $createAppointment->handle($request->validated(), $clinic);

        $audit->handle(
            $request->user(),
            $request,
            'clinic_appointments',
            'appointment_created',
            Appointment::class,
            $appointment->id,
            null,
            clinic: $clinic,
            metadata: [
                'patient_profile_id' => $appointment->patient_profile_id,
                'doctor_user_id' => $appointment->doctor_user_id,
                'scheduled_at' => $appointment->scheduled_at?->toIso8601String(),
            ],
        );

        return response()->json([
            'success' => true,
            'data' => $this->serializeAppointment($appointment),
        ], 201);
    }

    public function updateAppointment(
        ClinicAppointmentUpdateRequest $request,
        Appointment $appointment,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        $clinic = $this->clinic($request);
        $this->clinicAppointment($clinic, $appointment);
        $validated = $request->validated();

        $appointment = DB::transaction(function () use ($appointment, $clinic, $validated): Appointment {
            $lockedAppointment = Appointment::query()
                ->whereKey($appointment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->clinicAppointment($clinic, $lockedAppointment);

            if (array_key_exists('doctor_user_id', $validated) || array_key_exists('scheduled_at', $validated)) {
                $doctorProfile = $this->doctorScheduling->resolveClinicDoctor(
                    $clinic,
                    (int) ($validated['doctor_user_id'] ?? $lockedAppointment->doctor_user_id),
                    true,
                );
                $scheduledAt = $validated['scheduled_at'] ?? $lockedAppointment->scheduled_at;
                $this->doctorScheduling->assertBookable($doctorProfile, $clinic, Carbon::parse((string) $scheduledAt), $lockedAppointment->id);
            }

            $lockedAppointment->update($validated);

            return $lockedAppointment->fresh([
                'patientProfile.user:id,name,email,phone_number',
                'doctor:id,name',
                'doctor.doctorProfile:id,user_id,specialty',
            ]);
        });

        $audit->handle(
            $request->user(),
            $request,
            'clinic_appointments',
            'appointment_updated',
            Appointment::class,
            $appointment->id,
            null,
            clinic: $clinic,
            metadata: ['changed_fields' => array_keys($request->validated())],
        );

        return response()->json(['success' => true, 'data' => $this->serializeAppointment($appointment)]);
    }

    public function updateAppointmentStatus(
        ClinicAppointmentStatusUpdateRequest $request,
        Appointment $appointment,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        $clinic = $this->clinic($request);
        $nextStatus = AppointmentStatus::from((string) $request->validated('status'));
        $result = DB::transaction(function () use ($appointment, $clinic, $nextStatus): array {
            $lockedAppointment = Appointment::query()
                ->whereKey($appointment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->clinicAppointment($clinic, $lockedAppointment);
            $previousStatus = $lockedAppointment->status->value;
            $this->doctorScheduling->assertAppointmentStatusTransition($lockedAppointment->status, $nextStatus);
            $lockedAppointment->update(['status' => $nextStatus]);
            $lockedAppointment->load([
                'patientProfile.user:id,name,email,phone_number',
                'doctor:id,name',
                'doctor.doctorProfile:id,user_id,specialty',
            ]);

            return [
                'appointment' => $lockedAppointment,
                'previous_status' => $previousStatus,
            ];
        });
        $appointment = $result['appointment'];

        $audit->handle(
            $request->user(),
            $request,
            'clinic_appointments',
            'appointment_status_updated',
            Appointment::class,
            $appointment->id,
            null,
            clinic: $clinic,
            metadata: ['from' => $result['previous_status'], 'to' => $request->validated('status')],
        );

        return response()->json(['success' => true, 'data' => $this->serializeAppointment($appointment)]);
    }

    public function storeDoctor(
        ClinicDoctorStoreRequest $request,
        CreateClinicDoctor $createDoctor,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        $clinic = $this->clinic($request);
        $user = $createDoctor->handle($request->validated(), $clinic);

        $membership = $clinic->memberships()
            ->where('user_id', $user->id)
            ->with([
                'role:id,key,name',
                'user:id,name,email,phone_number,country_code,status,last_login_at',
                'user.doctorProfile:id,user_id,clinic_id,medical_license,specialty',
            ])
            ->firstOrFail();

        $audit->handle(
            $request->user(),
            $request,
            'clinic_team',
            'doctor_created',
            User::class,
            $user->id,
            $user->name,
            clinic: $clinic,
            countryCode: $user->country_code,
            metadata: ['role' => RoleKey::Doctor->value, 'requires_password_reset' => true],
        );

        return response()->json([
            'success' => true,
            'data' => $this->serializeMember($membership),
            'meta' => ['requires_password_reset' => true],
        ], 201);
    }

    public function updateMember(
        ClinicMemberUpdateRequest $request,
        User $member,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        $clinic = $this->clinic($request);
        $this->clinicMember($clinic, $member);

        $before = $member->only(['name', 'email', 'phone_number', 'country_code']);
        $member->update($request->validated());
        $member->refresh();

        $membership = $clinic->memberships()
            ->where('user_id', $member->id)
            ->with([
                'role:id,key,name',
                'user:id,name,email,phone_number,country_code,status,last_login_at',
                'user.doctorProfile:id,user_id,clinic_id,medical_license,specialty',
            ])
            ->firstOrFail();

        $after = $member->only(array_keys($before));

        $audit->handle(
            $request->user(),
            $request,
            'clinic_team',
            'member_updated',
            User::class,
            $member->id,
            $member->name,
            clinic: $clinic,
            metadata: ['changed_fields' => $this->changedFields($before, $after)],
        );

        return response()->json(['success' => true, 'data' => $this->serializeMember($membership)]);
    }

    public function updateMemberStatus(
        ClinicMemberStatusUpdateRequest $request,
        User $member,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        $clinic = $this->clinic($request);
        $this->clinicMember($clinic, $member);

        if ($request->user()->id === $member->id) {
            return response()->json([
                'success' => false,
                'message' => 'No puedes cambiar tu propio estado.',
            ], 422);
        }

        $previousStatus = $member->status->value;
        $member->update(['status' => $request->validated('status')]);

        $membership = $clinic->memberships()
            ->where('user_id', $member->id)
            ->with([
                'role:id,key,name',
                'user:id,name,email,phone_number,country_code,status,last_login_at',
                'user.doctorProfile:id,user_id,clinic_id,medical_license,specialty',
            ])
            ->firstOrFail();

        $audit->handle(
            $request->user(),
            $request,
            'clinic_team',
            'member_status_updated',
            User::class,
            $member->id,
            $member->name,
            clinic: $clinic,
            metadata: ['from' => $previousStatus, 'to' => $request->validated('status')],
        );

        return response()->json(['success' => true, 'data' => $this->serializeMember($membership)]);
    }

    public function destroyMember(
        Request $request,
        User $member,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        $clinic = $this->clinic($request);
        $this->clinicMember($clinic, $member);

        if ($request->user()->id === $member->id) {
            return response()->json([
                'success' => false,
                'message' => 'No puedes eliminarte a ti mismo del equipo.',
            ], 422);
        }

        $clinic->memberships()
            ->where('user_id', $member->id)
            ->update(['status' => ClinicMembershipStatus::Suspended->value]);

        $audit->handle(
            $request->user(),
            $request,
            'clinic_team',
            'member_removed',
            User::class,
            $member->id,
            $member->name,
            clinic: $clinic,
        );

        return response()->json(['success' => true]);
    }

    public function destroyAppointment(
        Request $request,
        int $appointment,
    ): JsonResponse {   
        $clinic = $this->clinic($request);

        $appointmentModel = $clinic
            ->appointments()
            ->whereKey($appointment)
            ->firstOrFail();

        $appointmentModel->delete();

        return response()->json([
            'success' => true,
            'message' => 'Cita eliminada correctamente.',
        ]);
    }


    private function clinic(Request $request): Clinic
    {
        return $request->attributes->get('clinic');
    }

    /** @return Collection<int, ClinicMembership> */
    private function activeMembers(Clinic $clinic): Collection
    {
        return $clinic->memberships()
            ->where('status', ClinicMembershipStatus::Active->value)
            ->with([
                'role:id,key,name',
                'user:id,name,email,phone_number,country_code,status,last_login_at',
                'user.doctorProfile:id,user_id,clinic_id,medical_license,specialty',
            ])
            ->get();
    }

    /** @return Collection<int, mixed> */
    private function recentPatients(Clinic $clinic, int $limit): Collection
    {
        return $clinic->patientProfiles()
            ->with(['user:id,name,email,phone_number,status,created_at', 'latestIntake.registrar:id,name'])
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    private function clinicPatient(Clinic $clinic, PatientProfile $patient): PatientProfile
    {
        return $clinic->patientProfiles()->whereKey($patient->id)->firstOrFail();
    }

    private function clinicAppointment(Clinic $clinic, Appointment $appointment): Appointment
    {
        if ($appointment->clinic_id !== $clinic->id) {
            abort(404);
        }

        return $appointment;
    }

    private function clinicMember(Clinic $clinic, User $member): void
    {
        $exists = $clinic->memberships()
            ->where('user_id', $member->id)
            ->where('status', ClinicMembershipStatus::Active->value)
            ->exists();

        if (! $exists) {
            abort(404);
        }
    }

    /** @return Builder<Appointment> */
    private function patientAppointments(Clinic $clinic, PatientProfile $patient): Builder
    {
        return Appointment::query()
            ->where('clinic_id', $clinic->id)
            ->where('patient_profile_id', $patient->id)
            ->with(['doctor:id,name', 'doctor.doctorProfile:id,user_id,specialty']);
    }

    /** @return array{total: int, active: int, suspended: int} */
    private function patientSummary(Clinic $clinic): array
    {
        $patients = $clinic->patientProfiles();

        return [
            'total' => $patients->count(),
            'active' => (clone $patients)->whereHas('user', fn (Builder $query) => $query->where('status', UserStatus::Active->value))->count(),
            'suspended' => (clone $patients)->whereHas('user', fn (Builder $query) => $query->where('status', UserStatus::Suspended->value))->count(),
        ];
    }

    private function patientCodeId(string $search): ?int
    {
        if (preg_match('/^PAC-(\d+)$/i', trim($search), $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /** @return Collection<int, Appointment> */
    private function appointmentsForDay(Clinic $clinic, Carbon $day): Collection
    {
        return $this->appointmentsQuery($clinic)
            ->whereDate('scheduled_at', $day->toDateString())
            ->orderBy('scheduled_at')
            ->get();
    }

    /** @return array<string, int> */
    private function appointmentSummary(Collection $appointments): array
    {
        return [
            'today_total' => $appointments->count(),
            'confirmed' => $appointments->where('status', AppointmentStatus::Confirmed)->count(),
            'pending' => $appointments->where('status', AppointmentStatus::Pending)->count(),
            'waiting' => $appointments->where('status', AppointmentStatus::Waiting)->count(),
            'in_consultation' => $appointments->where('status', AppointmentStatus::InConsultation)->count(),
            'no_show' => $appointments->where('status', AppointmentStatus::NoShow)->count(),
            'canceled' => $appointments->where('status', AppointmentStatus::Canceled)->count(),
        ];
    }

    private function appointmentsQuery(Clinic $clinic): Relation
    {
        return $clinic->appointments()
            ->where(function (Builder $query) use ($clinic): void {
                $query
                    ->whereNull('doctor_user_id')
                    ->orWhereHas('doctor', function (Builder $doctorQuery) use ($clinic): void {
                        $doctorQuery
                            ->where('status', UserStatus::Active->value)
                            ->whereHas('doctorProfile', fn (Builder $profileQuery): Builder => $profileQuery->where('clinic_id', $clinic->id))
                            ->whereHas('clinicMemberships', function (Builder $membershipQuery) use ($clinic): void {
                                $membershipQuery
                                    ->where('clinic_id', $clinic->id)
                                    ->where('status', ClinicMembershipStatus::Active->value)
                                    ->whereHas('role', fn (Builder $roleQuery): Builder => $roleQuery->where('key', RoleKey::Doctor->value));
                            });
                    });
            })
            ->with([
                'patientProfile.user:id,name,email,phone_number',
                'doctor:id,name',
                'doctor.doctorProfile:id,user_id,specialty',
            ]);
    }

    /** @return array<string, mixed> */
    private function serializeClinic(Clinic $clinic): array
    {
        $preferences = $this->clinicPreferences($clinic);

        return [
            'id' => $clinic->id,
            'name' => $clinic->name,
            'country_code' => $clinic->country_code,
            'contact_phone' => $clinic->contact_phone,
            'address' => $clinic->address,
            'status' => $clinic->status->value,
            'preferences' => $preferences,
            'emergency' => $this->emergencyPolicies->resolve($clinic),
        ];
    }

    /** @return array<string, mixed> */
    private function clinicPreferences(Clinic $clinic): array
    {
        $stored = is_array($clinic->preferences) ? $clinic->preferences : [];

        return [
            ...array_replace(
                self::DEFAULT_CLINIC_PREFERENCES,
                array_intersect_key($stored, self::DEFAULT_CLINIC_PREFERENCES),
            ),
            'emergency' => $this->emergencyPolicies->localPolicy($clinic),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeMember(ClinicMembership $membership): array
    {
        $doctor = $membership->user->doctorProfile;

        return [
            'id' => $membership->user->id,
            'name' => $membership->user->name,
            'email' => $membership->user->email,
            'phone_number' => $membership->user->phone_number,
            'country_code' => $membership->user->country_code,
            'status' => $membership->user->status->value,
            'role' => $membership->role->key,
            'role_name' => $membership->role->name,
            'specialty' => $doctor?->specialty,
            'medical_license' => $doctor?->medical_license,
            'last_login_at' => $membership->user->last_login_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializePatient(PatientProfile $patient): array
    {
        return [
            'id' => $patient->id,
            'patient_code' => 'PAC-'.str_pad((string) $patient->id, 6, '0', STR_PAD_LEFT),
            'name' => $patient->user?->name,
            'email' => $patient->user?->email,
            'phone_number' => $patient->user?->phone_number,
            'status' => $patient->user?->status?->value,
            'insurance' => $patient->insurance,
            'is_returning_patient' => (bool) $patient->is_returning_patient,
            'whatsapp_consent' => (bool) $patient->whatsapp_consent,
            'created_at' => $patient->user?->created_at?->toIso8601String(),
            'age' => $patient->date_of_birth?->age,
            'last_appointment' => $patient->relationLoaded('lastAppointment')
                ? $this->serializePatientAppointment($patient->lastAppointment)
                : null,
            'next_appointment' => $patient->relationLoaded('nextAppointment')
                ? $this->serializePatientAppointment($patient->nextAppointment)
                : null,
            'triage' => $this->serializeTriage($patient->latestIntake),
        ];
    }

    /** @return array<string, mixed> */
    private function serializePatientProfile(PatientProfile $patient): array
    {
        return [
            'document_type' => $patient->document_type,
            'document_number' => $patient->document_number,
            'date_of_birth' => $patient->date_of_birth?->toDateString(),
            'sex' => $patient->sex,
            'address' => $patient->address,
            'emergency_contact' => $patient->emergency_contact,
            'insurance' => $patient->insurance,
            'is_returning_patient' => (bool) $patient->is_returning_patient,
            'whatsapp_consent' => (bool) $patient->whatsapp_consent,
            'country_code' => $patient->user?->country_code,
        ];
    }

    /** @return array<string, mixed>|null */
    private function serializeIntakeSummary(?PatientIntake $intake): ?array
    {
        if ($intake === null) {
            return null;
        }

        return [
            'reason_category' => $intake->reason_category,
            'requested_doctor' => $intake->requestedDoctor === null ? null : [
                'id' => $intake->requestedDoctor->id,
                'name' => $intake->requestedDoctor->name,
                'specialty' => $intake->requestedDoctor->doctorProfile?->specialty,
            ],
            'specialty' => $intake->specialty?->name,
            'triage' => [
                'temperature_c' => $intake->temperature_c,
                'systolic_bp' => $intake->systolic_bp,
                'diastolic_bp' => $intake->diastolic_bp,
                'heart_rate' => $intake->heart_rate,
                'respiratory_rate' => $intake->respiratory_rate,
                'oxygen_saturation' => $intake->oxygen_saturation,
                'priority' => $intake->triage_priority,
                'recorded_at' => $intake->created_at?->toIso8601String(),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function serializePatientAppointment(?Appointment $appointment): ?array
    {
        if ($appointment === null) {
            return null;
        }

        return [
            'id' => $appointment->id,
            'scheduled_at' => $appointment->scheduled_at?->toIso8601String(),
            'appointment_type' => $appointment->appointment_type,
            'status' => $appointment->status->value,
            'status_label' => $appointment->status->label(),
            'notes' => $appointment->notes,
            'doctor' => $appointment->doctor === null ? null : [
                'id' => $appointment->doctor->id,
                'name' => $appointment->doctor->name,
                'specialty' => $appointment->doctor->doctorProfile?->specialty,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function administrativePatientSnapshot(PatientProfile $patient): array
    {
        return [
            'name' => $patient->user?->name,
            'email' => $patient->user?->email,
            'phone_number' => $patient->user?->phone_number,
            'country_code' => $patient->user?->country_code,
            'document_type' => $patient->document_type,
            'document_number' => $patient->document_number,
            'date_of_birth' => $patient->date_of_birth?->toDateString(),
            'sex' => $patient->sex,
            'address' => $patient->address,
            'emergency_contact' => $patient->emergency_contact,
            'insurance' => $patient->insurance,
            'is_returning_patient' => (bool) $patient->is_returning_patient,
            'whatsapp_consent' => (bool) $patient->whatsapp_consent,
        ];
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after @return array<int, string> */
    private function changedFields(array $before, array $after): array
    {
        return array_keys(array_filter(
            $after,
            fn (mixed $value, string $field): bool => $value !== ($before[$field] ?? null),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    private function safeCsvValue(mixed $value): string
    {
        $normalized = trim((string) ($value ?? ''));

        return preg_match('/^[=+\-@]/u', $normalized) === 1 ? "'{$normalized}" : $normalized;
    }

    /** @return array<string, mixed>|null */
    private function serializeTriage(?PatientIntake $intake): ?array
    {
        if ($intake === null) {
            return null;
        }

        return [
            'temperature_c' => $intake->temperature_c,
            'systolic_bp' => $intake->systolic_bp,
            'diastolic_bp' => $intake->diastolic_bp,
            'heart_rate' => $intake->heart_rate,
            'respiratory_rate' => $intake->respiratory_rate,
            'oxygen_saturation' => $intake->oxygen_saturation,
            'priority' => $intake->triage_priority,
            'notes' => $intake->triage_notes,
            'recorded_at' => $intake->created_at?->toIso8601String(),
            'registrar_name' => $intake->registrar?->name,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeAppointment(Appointment $appointment): array
    {
        $patient = $appointment->patientProfile?->user;
        $doctor = $appointment->doctor;

        return [
            'id' => $appointment->id,
            'scheduled_at' => $appointment->scheduled_at?->toIso8601String(),
            'scheduled_date' => $appointment->scheduled_at?->toDateString(),
            'time' => $appointment->scheduled_at?->format('H:i'),
            'appointment_type' => $appointment->appointment_type,
            'status' => $appointment->status->value,
            'status_label' => $appointment->status->label(),
            'notes' => $appointment->notes,
            'patient' => [
                'id' => $appointment->patient_profile_id,
                'patient_code' => 'PAC-'.str_pad((string) $appointment->patient_profile_id, 6, '0', STR_PAD_LEFT),
                'name' => $patient?->name,
                'phone_number' => $patient?->phone_number,
            ],
            'doctor' => $doctor === null ? null : [
                'id' => $doctor->id,
                'name' => $doctor->name,
                'specialty' => $doctor->doctorProfile?->specialty,
            ],
        ];
    }
}
