<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Admin\RecordAdminAuditEvent;
use App\Actions\Clinic\CreateClinicAppointment;
use App\Actions\Clinic\CreateClinicPatient;
use App\Actions\Clinic\CreateClinicReceptionist;
use App\Actions\Clinic\CreateClinicScheduleBlock;
use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Enums\ClinicMembershipStatus;
use App\Enums\RoleKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ClinicMemberStoreRequest;
use App\Http\Requests\Api\V1\ClinicPatientStoreRequest;
use App\Http\Requests\Api\V1\ClinicSettingsUpdateRequest;
use App\Http\Requests\Api\V1\ClinicAppointmentStoreRequest;
use App\Http\Requests\Api\V1\ClinicScheduleBlockStoreRequest;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\PatientIntake;
use App\Models\User;
use App\Models\ClinicScheduleBlock;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ClinicDashboardController extends Controller
{
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
                'user:id,name,email,phone_number,status,last_login_at',
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
            ->with(['user:id,name,email,phone_number,status,created_at', 'latestIntake.registrar:id,name'])
            ->latest('id');
        $search = trim((string) $request->query('search', ''));

        if ($search !== '') {
            $query->whereHas('user', function ($userQuery) use ($search): void {
                $userQuery
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%");
            });
        }

        $patients = $query->paginate(min(max((int) $request->query('per_page', 10), 1), 100));

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
            ],
        ]);
    }

    public function storePatient(
        ClinicPatientStoreRequest $request,
        CreateClinicPatient $createPatient,
    ): JsonResponse {
        $patient = $createPatient
            ->handle($request->validated(), $this->clinic($request), $request->user())
            ->load(['user', 'latestIntake.registrar:id,name']);

        return response()->json([
            'success' => true,
            'data' => $this->serializePatient($patient),
        ], 201);
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

    public function storeAppointment(ClinicAppointmentStoreRequest $request,CreateClinicAppointment $createAppointment,): JsonResponse 
    {
        $appointment = $createAppointment
        ->handle(
            $request->validated(),
            $this->clinic($request)
        )
        ->load([
            'patientProfile.user:id,name,email,phone_number',
            'doctor:id,name',
            'doctor.doctorProfile:id,user_id,specialty',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->serializeAppointment($appointment),
        ], 201);
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
        $clinic = $this->clinic($request);
        $before = $clinic->only(['name', 'contact_phone', 'address']);
        $clinic->fill($request->validated())->save();

        $audit->handle(
            $request->user(),
            $request,
            'clinic_settings',
            'clinic_settings_updated',
            Clinic::class,
            $clinic->id,
            $clinic->name,
            clinic: $clinic,
            metadata: ['before' => $before, 'after' => $clinic->only(array_keys($before))],
        );

        return response()->json([
            'success' => true,
            'data' => ['clinic' => $this->serializeClinic($clinic->fresh())],
        ]);
    }

    public function scheduleBlocks(Request $request): JsonResponse
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

    $blocks = ClinicScheduleBlock::query()
        ->where('clinic_id', $this->clinic($request)->id)
        ->where('starts_at', '<=', $to)
        ->where('ends_at', '>=', $from)
        ->with([
            'doctor:id,name',
            'creator:id,name',
        ])
        ->orderBy('starts_at')
        ->get();

    return response()->json([
        'success' => true,
        'data' => $blocks->map(
            fn (ClinicScheduleBlock $block): array =>
                $this->serializeScheduleBlock($block)
        )->values(),
    ]);
}
public function storeScheduleBlock(
    ClinicScheduleBlockStoreRequest $request,
    CreateClinicScheduleBlock $createScheduleBlock,
): JsonResponse {
    $block = $createScheduleBlock
        ->handle(
            $request->validated(),
            $this->clinic($request),
            $request->user(),
        )
        ->load([
            'doctor:id,name',
            'creator:id,name',
        ]);

    return response()->json([
        'success' => true,
        'data' => $this->serializeScheduleBlock($block),
    ], 201);
}

    private function clinic(Request $request): Clinic
    {
        return $request->attributes->get('clinic');
    }

    /** @return Collection<int, ClinicMembership> */
    private function activeMembers(Clinic $clinic)
    {
        return $clinic->memberships()
            ->where('status', ClinicMembershipStatus::Active->value)
            ->with([
                'role:id,key,name',
                'user:id,name,email,phone_number,status,last_login_at',
                'user.doctorProfile:id,user_id,clinic_id,medical_license,specialty',
            ])
            ->get();
    }

    /** @return Collection<int, mixed> */
    private function recentPatients(Clinic $clinic, int $limit)
    {
        return $clinic->patientProfiles()
            ->with(['user:id,name,email,phone_number,status,created_at', 'latestIntake.registrar:id,name'])
            ->latest('id')
            ->limit($limit)
            ->get();
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

    /** @return Relation */
    private function appointmentsQuery(Clinic $clinic)
    {
        return $clinic->appointments()->with([
            'patientProfile.user:id,name,email,phone_number',
            'doctor:id,name',
            'doctor.doctorProfile:id,user_id,specialty',
        ]);
    }

    /** @return array<string, mixed> */
    private function serializeClinic(Clinic $clinic): array
    {
        return [
            'id' => $clinic->id,
            'name' => $clinic->name,
            'country_code' => $clinic->country_code,
            'contact_phone' => $clinic->contact_phone,
            'address' => $clinic->address,
            'status' => $clinic->status->value,
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
            'status' => $membership->user->status->value,
            'role' => $membership->role->key,
            'role_name' => $membership->role->name,
            'specialty' => $doctor?->specialty,
            'medical_license' => $doctor?->medical_license,
            'last_login_at' => $membership->user->last_login_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializePatient($patient): array
    {
        return [
            'id' => $patient->id,
            'patient_code' => 'PAC-'.str_pad((string) $patient->id, 6, '0', STR_PAD_LEFT),
            'name' => $patient->user?->name,
            'email' => $patient->user?->email,
            'phone_number' => $patient->user?->phone_number,
            'status' => $patient->user?->status?->value,
            'created_at' => $patient->user?->created_at?->toIso8601String(),
            'triage' => $this->serializeTriage($patient->latestIntake),
        ];
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
            'appointment_type' => AppointmentType::tryFrom($appointment->appointment_type)?->label() ?? $appointment->appointment_type,
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
    private function serializeScheduleBlock(
    ClinicScheduleBlock $block,
): array {
    return [
        'id' => $block->id,

        'starts_at' => $block->starts_at?->toIso8601String(),
        'ends_at' => $block->ends_at?->toIso8601String(),

        'scheduled_date' => $block->starts_at?->toDateString(),
        'start_time' => $block->starts_at?->format('H:i'),
        'end_time' => $block->ends_at?->format('H:i'),

        'reason' => $block->reason,

        'scope' => $block->doctor_user_id === null
            ? 'clinic'
            : 'doctor',

        'doctor' => $block->doctor === null
            ? null
            : [
                'id' => $block->doctor->id,
                'name' => $block->doctor->name,
            ],

        'created_by' => [
            'id' => $block->creator->id,
            'name' => $block->creator->name,
        ],
    ];
}
}
