<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LaboratoryOrderStatus;
use App\Enums\PrescriptionStatus;
use App\Enums\TeleconsultationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DoctorMessageStoreRequest;
use App\Http\Requests\Api\V1\DoctorPrescriptionStoreRequest;
use App\Http\Requests\Api\V1\DoctorPrescriptionUpdateRequest;
use App\Http\Requests\Api\V1\LaboratoryOrderStatusUpdateRequest;
use App\Http\Requests\Api\V1\LaboratoryOrderStoreRequest;
use App\Http\Requests\Api\V1\TeleconsultationStatusUpdateRequest;
use App\Http\Requests\Api\V1\TeleconsultationStoreRequest;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Consultation;
use App\Models\DoctorMessage;
use App\Models\DoctorPrescription;
use App\Models\LaboratoryOrder;
use App\Models\PatientProfile;
use App\Models\Teleconsultation;
use App\Models\User;
use App\Support\Clinical\DoctorClinicScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DoctorWorkspaceController extends Controller
{
    public function prescriptions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(array_column(PrescriptionStatus::cases(), 'value'))],
        ]);
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $query = $this->doctorQuery(DoctorPrescription::query(), $doctor, $clinic)
            ->with(['clinic', 'patientProfile.user', 'consultation'])
            ->when(isset($validated['status']), fn (Builder $builder): Builder => $builder->where('status', $validated['status']))
            ->orderByDesc('issued_at')
            ->orderByDesc('id');
        $prescriptions = $query->get();

        return response()->json([
            'success' => true,
            'data' => $prescriptions->map(fn (DoctorPrescription $prescription): array => $this->serializePrescription($prescription))->values(),
            'meta' => [
                'total' => $prescriptions->count(),
                'active' => $prescriptions->where('status', PrescriptionStatus::Active)->count(),
                'renewal_due' => $prescriptions->where('status', PrescriptionStatus::RenewalDue)->count(),
                'pending' => $prescriptions->whereIn('status', [PrescriptionStatus::Draft, PrescriptionStatus::RenewalDue])->count(),
            ],
        ]);
    }

    public function storePrescription(DoctorPrescriptionStoreRequest $request): JsonResponse
    {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $data = $request->validated();
        $patient = PatientProfile::query()->findOrFail($data['patient_profile_id']);
        $this->assertPatientAccessible($doctor, $clinic, $patient);
        $consultation = isset($data['consultation_id'])
            ? $this->resolveOwnedConsultation($doctor, $clinic, (int) $data['consultation_id'], $patient->id)
            : null;

        $prescription = DoctorPrescription::query()->create([
            'clinic_id' => $clinic?->id,
            'doctor_user_id' => $doctor->id,
            'patient_profile_id' => $patient->id,
            'consultation_id' => $consultation?->id,
            'medication' => trim($data['medication']),
            'dosage' => $data['dosage'] ?? null,
            'frequency' => $data['frequency'] ?? null,
            'duration_days' => $data['duration_days'] ?? null,
            'status' => PrescriptionStatus::from($data['status'] ?? PrescriptionStatus::Active->value),
            'issued_at' => $data['issued_at'] ?? now(),
            'expires_at' => $data['expires_at'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->serializePrescription($prescription->fresh(['clinic', 'patientProfile.user', 'consultation'])),
        ], 201);
    }

    public function updatePrescription(
        DoctorPrescriptionUpdateRequest $request,
        DoctorPrescription $prescription,
    ): JsonResponse {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $data = $request->validated();
        $prescription = DB::transaction(function () use ($doctor, $clinic, $prescription, $data): DoctorPrescription {
            $lockedPrescription = $this->doctorQuery(DoctorPrescription::query(), $doctor, $clinic)
                ->whereKey($prescription->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedPrescription === null) {
                abort(404, 'Receta no encontrada.');
            }

            if (isset($data['status'])) {
                $this->assertPrescriptionStatusTransition($lockedPrescription->status, PrescriptionStatus::from($data['status']));
            }

            $lockedPrescription->update($data);

            return $lockedPrescription->fresh(['clinic', 'patientProfile.user', 'consultation']);
        });

        return response()->json([
            'success' => true,
            'data' => $this->serializePrescription($prescription),
        ]);
    }

    public function laboratory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(array_column(LaboratoryOrderStatus::cases(), 'value'))],
        ]);
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $query = $this->doctorQuery(LaboratoryOrder::query(), $doctor, $clinic)
            ->with(['clinic', 'patientProfile.user', 'consultation'])
            ->when(isset($validated['status']), fn (Builder $builder): Builder => $builder->where('status', $validated['status']))
            ->orderByDesc('ordered_at')
            ->orderByDesc('id');
        $orders = $query->get();

        return response()->json([
            'success' => true,
            'data' => $orders->map(fn (LaboratoryOrder $order): array => $this->serializeLaboratoryOrder($order))->values(),
            'meta' => [
                'total' => $orders->count(),
                'requested' => $orders->where('status', LaboratoryOrderStatus::Requested)->count(),
                'in_progress' => $orders->where('status', LaboratoryOrderStatus::InProgress)->count(),
                'ready' => $orders->where('status', LaboratoryOrderStatus::Ready)->count(),
                'reviewed' => $orders->where('status', LaboratoryOrderStatus::Reviewed)->count(),
                'critical' => $orders->where('is_critical', true)->count(),
            ],
        ]);
    }

    public function storeLaboratoryOrder(LaboratoryOrderStoreRequest $request): JsonResponse
    {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $data = $request->validated();
        $patient = PatientProfile::query()->findOrFail($data['patient_profile_id']);
        $this->assertPatientAccessible($doctor, $clinic, $patient);
        $consultation = isset($data['consultation_id'])
            ? $this->resolveOwnedConsultation($doctor, $clinic, (int) $data['consultation_id'], $patient->id)
            : null;
        $order = LaboratoryOrder::query()->create([
            'clinic_id' => $clinic?->id,
            'doctor_user_id' => $doctor->id,
            'patient_profile_id' => $patient->id,
            'consultation_id' => $consultation?->id,
            'study_name' => trim($data['study_name']),
            'status' => LaboratoryOrderStatus::Requested,
            'priority' => $data['priority'] ?? 'normal',
            'ordered_at' => now(),
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->serializeLaboratoryOrder($order->fresh(['clinic', 'patientProfile.user', 'consultation'])),
        ], 201);
    }

    public function updateLaboratoryOrderStatus(
        LaboratoryOrderStatusUpdateRequest $request,
        LaboratoryOrder $order,
    ): JsonResponse {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $data = $request->validated();
        $order = DB::transaction(function () use ($doctor, $clinic, $order, $data): LaboratoryOrder {
            $lockedOrder = $this->doctorQuery(LaboratoryOrder::query(), $doctor, $clinic)
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedOrder === null) {
                abort(404, 'Resultado de laboratorio no encontrado.');
            }

            $nextStatus = LaboratoryOrderStatus::from($data['status']);
            $this->assertLaboratoryStatusTransition($lockedOrder->status, $nextStatus);
            $updates = ['status' => $nextStatus];

            foreach (['is_critical', 'result_summary', 'result_at'] as $field) {
                if (array_key_exists($field, $data)) {
                    $updates[$field] = $data[$field];
                }
            }

            if ($nextStatus === LaboratoryOrderStatus::Ready && ! array_key_exists('result_at', $updates)) {
                $updates['result_at'] = now();
            }

            $lockedOrder->update($updates);

            return $lockedOrder->fresh(['clinic', 'patientProfile.user', 'consultation']);
        });

        return response()->json([
            'success' => true,
            'data' => $this->serializeLaboratoryOrder($order),
        ]);
    }

    public function teleconsultations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'status' => ['nullable', 'string', Rule::in(array_column(TeleconsultationStatus::cases(), 'value'))],
        ]);
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from'])->startOfDay() : now()->startOfDay();
        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to'])->endOfDay() : now()->addDays(30)->endOfDay();
        if ($to->lessThan($from)) {
            throw ValidationException::withMessages(['to' => ['El rango de fechas no es válido.']]);
        }

        $sessions = $this->doctorQuery(Teleconsultation::query(), $doctor, $clinic)
            ->with(['clinic', 'patientProfile.user', 'appointment', 'consultation'])
            ->whereBetween('scheduled_at', [$from, $to])
            ->when(isset($validated['status']), fn (Builder $builder): Builder => $builder->where('status', $validated['status']))
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $sessions->map(fn (Teleconsultation $session): array => $this->serializeTeleconsultation($session))->values(),
            'meta' => [
                'total' => $sessions->count(),
                'scheduled' => $sessions->where('status', TeleconsultationStatus::Scheduled)->count(),
                'waiting' => $sessions->where('status', TeleconsultationStatus::Waiting)->count(),
                'in_progress' => $sessions->where('status', TeleconsultationStatus::InProgress)->count(),
                'completed' => $sessions->where('status', TeleconsultationStatus::Completed)->count(),
                'connection' => [
                    'status' => 'not_configured',
                    'message' => 'No hay un proveedor de video configurado para esta práctica.',
                ],
            ],
        ]);
    }

    public function storeTeleconsultation(TeleconsultationStoreRequest $request): JsonResponse
    {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $data = $request->validated();
        $patient = PatientProfile::query()->findOrFail($data['patient_profile_id']);
        $this->assertPatientAccessible($doctor, $clinic, $patient);
        $appointment = isset($data['appointment_id'])
            ? $this->resolveOwnedAppointment($doctor, $clinic, (int) $data['appointment_id'], $patient->id)
            : null;
        $consultation = isset($data['consultation_id'])
            ? $this->resolveOwnedConsultation($doctor, $clinic, (int) $data['consultation_id'], $patient->id)
            : null;
        $session = Teleconsultation::query()->create([
            'clinic_id' => $clinic?->id,
            'doctor_user_id' => $doctor->id,
            'patient_profile_id' => $patient->id,
            'appointment_id' => $appointment?->id,
            'consultation_id' => $consultation?->id,
            'status' => TeleconsultationStatus::Scheduled,
            'scheduled_at' => $data['scheduled_at'],
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->serializeTeleconsultation($session->fresh(['clinic', 'patientProfile.user', 'appointment', 'consultation'])),
        ], 201);
    }

    public function updateTeleconsultationStatus(
        TeleconsultationStatusUpdateRequest $request,
        Teleconsultation $teleconsultation,
    ): JsonResponse {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $teleconsultation = DB::transaction(function () use ($doctor, $clinic, $teleconsultation, $request): Teleconsultation {
            $lockedTeleconsultation = $this->doctorQuery(Teleconsultation::query(), $doctor, $clinic)
                ->whereKey($teleconsultation->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedTeleconsultation === null) {
                abort(404, 'Teleconsulta no encontrada.');
            }

            $nextStatus = TeleconsultationStatus::from($request->validated('status'));
            $this->assertTeleconsultationStatusTransition($lockedTeleconsultation->status, $nextStatus);
            $updates = ['status' => $nextStatus];

            if ($nextStatus === TeleconsultationStatus::InProgress && $lockedTeleconsultation->started_at === null) {
                $updates['started_at'] = now();
            }
            if ($nextStatus === TeleconsultationStatus::Completed && $lockedTeleconsultation->ended_at === null) {
                $updates['ended_at'] = now();
            }

            $lockedTeleconsultation->update($updates);

            return $lockedTeleconsultation->fresh(['clinic', 'patientProfile.user', 'appointment', 'consultation']);
        });

        return response()->json([
            'success' => true,
            'data' => $this->serializeTeleconsultation($teleconsultation),
        ]);
    }

    public function messages(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patient_profile_id' => ['nullable', 'integer', 'exists:patient_profiles,id'],
        ]);
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $query = $this->scopedMessagesQuery($doctor, $clinic)
            ->with(['sender', 'recipient', 'patientProfile.user'])
            ->when(isset($validated['patient_profile_id']), fn (Builder $builder): Builder => $builder->where('patient_profile_id', $validated['patient_profile_id']))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
        $unread = (clone $query)->where('recipient_user_id', $doctor->id)->whereNull('read_at')->count();
        $messages = $query->limit(100)->get();

        return response()->json([
            'success' => true,
            'data' => $messages->map(fn (DoctorMessage $message): array => $this->serializeMessage($message, $doctor))->values(),
            'meta' => ['total' => $messages->count(), 'unread' => $unread],
        ]);
    }

    public function storeMessage(DoctorMessageStoreRequest $request): JsonResponse
    {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $data = $request->validated();
        $patient = PatientProfile::query()->with('user')->findOrFail($data['patient_profile_id']);
        $this->assertPatientAccessible($doctor, $clinic, $patient);

        if ($patient->user === null) {
            abort(404, 'El paciente no tiene una cuenta de mensajería activa.');
        }

        $message = DoctorMessage::query()->create([
            'clinic_id' => $clinic?->id,
            'sender_user_id' => $doctor->id,
            'recipient_user_id' => $patient->user_id,
            'patient_profile_id' => $patient->id,
            'body' => trim($data['body']),
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->serializeMessage($message->fresh(['sender', 'recipient', 'patientProfile.user']), $doctor),
        ], 201);
    }

    public function markMessageRead(Request $request, DoctorMessage $message): JsonResponse
    {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $this->assertMessageAccessible($doctor, $clinic, $message);

        if ($message->recipient_user_id !== $doctor->id) {
            abort(403, 'Solo puedes marcar como leídos los mensajes recibidos.');
        }

        $message->update(['read_at' => now()]);

        return response()->json(['success' => true, 'data' => $this->serializeMessage($message->fresh(['sender', 'recipient', 'patientProfile.user']), $doctor)]);
    }

    public function reports(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from'])->startOfDay() : now()->startOfMonth();
        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to'])->endOfDay() : now()->endOfMonth();
        if ($to->lessThan($from)) {
            throw ValidationException::withMessages(['to' => ['El rango de fechas no es válido.']]);
        }

        $appointments = $this->doctorQuery(Appointment::query(), $doctor, $clinic)->whereBetween('scheduled_at', [$from, $to])->get();
        $consultations = $this->doctorQuery(Consultation::query(), $doctor, $clinic)->whereBetween('created_at', [$from, $to])->get();
        $prescriptions = $this->doctorQuery(DoctorPrescription::query(), $doctor, $clinic)->whereBetween('issued_at', [$from, $to])->get();
        $laboratoryOrders = $this->doctorQuery(LaboratoryOrder::query(), $doctor, $clinic)->whereBetween('ordered_at', [$from, $to])->get();
        $teleconsultations = $this->doctorQuery(Teleconsultation::query(), $doctor, $clinic)->whereBetween('scheduled_at', [$from, $to])->get();
        $patientCount = $appointments->pluck('patient_profile_id')
            ->merge($consultations->pluck('patient_profile_id'))
            ->unique()
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'metrics' => [
                    'appointments' => $appointments->count(),
                    'completed_appointments' => $appointments->where('status', 'completed')->count(),
                    'consultations' => $consultations->count(),
                    'completed_consultations' => $consultations->whereIn('status', ['completed', 'signed'])->count(),
                    'signed_consultations' => $consultations->where('status', 'signed')->count(),
                    'patients' => $patientCount,
                    'prescriptions' => $prescriptions->count(),
                    'active_prescriptions' => $prescriptions->where('status', PrescriptionStatus::Active)->count(),
                    'teleconsultations' => $teleconsultations->count(),
                    'completed_teleconsultations' => $teleconsultations->where('status', TeleconsultationStatus::Completed)->count(),
                    'laboratory_orders' => $laboratoryOrders->count(),
                    'pending_laboratory_orders' => $laboratoryOrders->whereIn('status', [LaboratoryOrderStatus::Requested, LaboratoryOrderStatus::InProgress])->count(),
                    'critical_laboratory_orders' => $laboratoryOrders->where('is_critical', true)->count(),
                ],
                'sources' => [
                    'appointments' => $appointments->count() > 0,
                    'consultations' => $consultations->count() > 0,
                    'prescriptions' => $prescriptions->count() > 0,
                    'laboratory' => $laboratoryOrders->count() > 0,
                    'telemedicine' => $teleconsultations->count() > 0,
                ],
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function epidemiology(Request $request): JsonResponse
    {
        $doctor = $request->user();
        $clinic = DoctorClinicScope::clinicOrNull($doctor);
        $from = now()->subDays(90)->startOfDay();
        $consultations = $this->doctorQuery(Consultation::query(), $doctor, $clinic)->whereBetween('created_at', [$from, now()]);
        $laboratoryOrders = $this->doctorQuery(LaboratoryOrder::query(), $doctor, $clinic)->whereBetween('ordered_at', [$from, now()]);
        $teleconsultations = $this->doctorQuery(Teleconsultation::query(), $doctor, $clinic)->whereBetween('scheduled_at', [$from, now()]);
        $prescriptions = $this->doctorQuery(DoctorPrescription::query(), $doctor, $clinic)->whereBetween('issued_at', [$from, now()]);
        $monitoredPatients = $this->connectedPatientsQuery($doctor, $clinic)
            ->whereHas('consultations', function (Builder $query) use ($doctor, $clinic, $from): void {
                $query->where('doctor_user_id', $doctor->id)->whereBetween('created_at', [$from, now()]);
                $this->applyClinicScope($query, $clinic);
            })
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'generated_at' => now()->toIso8601String(),
                'period_days' => 90,
                'analysis_available' => false,
                'message' => 'No hay un motor epidemiológico configurado. Se muestran únicamente fuentes clínicas disponibles.',
                'metrics' => [
                    'monitored_patients' => $monitoredPatients,
                    'active_alerts' => 0,
                    'high_risk_cases' => 0,
                    'trend' => 'not_configured',
                    'consultations' => $consultations->count(),
                    'laboratory_orders' => $laboratoryOrders->count(),
                    'teleconsultations' => $teleconsultations->count(),
                    'prescriptions' => $prescriptions->count(),
                ],
                'cohorts' => [],
                'alerts' => [],
                'sources' => [
                    'clinical_history' => ['status' => $consultations->count() > 0 ? 'available' : 'no_data', 'label' => 'Historia clínica interna'],
                    'laboratory' => ['status' => $laboratoryOrders->count() > 0 ? 'available' : 'no_data', 'label' => 'Resultados de laboratorio internos'],
                    'telemedicine' => ['status' => $teleconsultations->count() > 0 ? 'available' : 'no_data', 'label' => 'Telemedicina interna'],
                    'prescriptions' => ['status' => $prescriptions->count() > 0 ? 'available' : 'no_data', 'label' => 'Recetas internas'],
                ],
            ],
        ]);
    }

    /** @param Builder<DoctorPrescription|LaboratoryOrder|Teleconsultation|Consultation|Appointment> $query */
    private function doctorQuery(Builder $query, User $doctor, ?Clinic $clinic): Builder
    {
        $query->where('doctor_user_id', $doctor->id);

        return $this->applyClinicScope($query, $clinic);
    }

    /** @param Builder<DoctorMessage> $query */
    private function scopedMessagesQuery(User $doctor, ?Clinic $clinic): Builder
    {
        $query = DoctorMessage::query()->where(function (Builder $builder) use ($doctor): void {
            $builder->where('sender_user_id', $doctor->id)->orWhere('recipient_user_id', $doctor->id);
        });

        return $this->applyClinicScope($query, $clinic);
    }

    /** @param Builder<PatientProfile> $query */
    private function connectedPatientsQuery(User $doctor, ?Clinic $clinic): Builder
    {
        $query = PatientProfile::query();

        if ($clinic === null) {
            return $query->whereNull('clinic_id')->where('owner_doctor_user_id', $doctor->id);
        }

        return $query
            ->where('clinic_id', $clinic->id)
            ->where(function (Builder $patientQuery) use ($doctor, $clinic): void {
                $patientQuery
                    ->whereHas('appointments', function (Builder $appointmentQuery) use ($doctor, $clinic): void {
                        $appointmentQuery->where('doctor_user_id', $doctor->id)->where('clinic_id', $clinic->id);
                    })
                    ->orWhereHas('intakes', function (Builder $intakeQuery) use ($doctor, $clinic): void {
                        $intakeQuery->where('requested_doctor_user_id', $doctor->id)->where('clinic_id', $clinic->id);
                    });
            });
    }

    /** @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function applyClinicScope(Builder $query, ?Clinic $clinic): Builder
    {
        return $clinic === null ? $query->whereNull('clinic_id') : $query->where('clinic_id', $clinic->id);
    }

    private function assertPatientAccessible(User $doctor, ?Clinic $clinic, PatientProfile $patient): void
    {
        if ($clinic !== null) {
            if ($patient->clinic_id !== $clinic->id) {
                abort(404, 'Paciente no encontrado en la clínica del médico.');
            }

            return;
        }

        if ($patient->clinic_id !== null || $patient->owner_doctor_user_id !== $doctor->id) {
            abort(404, 'Paciente no encontrado.');
        }
    }

    private function resolveOwnedAppointment(User $doctor, ?Clinic $clinic, int $appointmentId, int $patientId): Appointment
    {
        $appointment = $this->doctorQuery(Appointment::query(), $doctor, $clinic)
            ->whereKey($appointmentId)
            ->where('patient_profile_id', $patientId)
            ->first();

        if ($appointment === null) {
            abort(404, 'Cita no encontrada.');
        }

        return $appointment;
    }

    private function resolveOwnedConsultation(User $doctor, ?Clinic $clinic, int $consultationId, int $patientId): Consultation
    {
        $consultation = $this->doctorQuery(Consultation::query(), $doctor, $clinic)
            ->whereKey($consultationId)
            ->where('patient_profile_id', $patientId)
            ->first();

        if ($consultation === null) {
            abort(404, 'Consulta no encontrada.');
        }

        return $consultation;
    }

    private function assertPrescriptionAccessible(User $doctor, ?Clinic $clinic, DoctorPrescription $prescription): void
    {
        if (! $this->doctorQuery(DoctorPrescription::query(), $doctor, $clinic)->whereKey($prescription->id)->exists()) {
            abort(404, 'Receta no encontrada.');
        }
    }

    private function assertLaboratoryOrderAccessible(User $doctor, ?Clinic $clinic, LaboratoryOrder $order): void
    {
        if (! $this->doctorQuery(LaboratoryOrder::query(), $doctor, $clinic)->whereKey($order->id)->exists()) {
            abort(404, 'Resultado de laboratorio no encontrado.');
        }
    }

    private function assertTeleconsultationAccessible(User $doctor, ?Clinic $clinic, Teleconsultation $session): void
    {
        if (! $this->doctorQuery(Teleconsultation::query(), $doctor, $clinic)->whereKey($session->id)->exists()) {
            abort(404, 'Teleconsulta no encontrada.');
        }
    }

    private function assertMessageAccessible(User $doctor, ?Clinic $clinic, DoctorMessage $message): void
    {
        if (! $this->scopedMessagesQuery($doctor, $clinic)->whereKey($message->id)->exists()) {
            abort(404, 'Mensaje no encontrado.');
        }
    }

    private function assertPrescriptionStatusTransition(PrescriptionStatus $current, PrescriptionStatus $next): void
    {
        $allowed = match ($current) {
            PrescriptionStatus::Draft => [PrescriptionStatus::Draft, PrescriptionStatus::Active, PrescriptionStatus::Canceled],
            PrescriptionStatus::Active => [PrescriptionStatus::Active, PrescriptionStatus::RenewalDue, PrescriptionStatus::Completed, PrescriptionStatus::Canceled],
            PrescriptionStatus::RenewalDue => [PrescriptionStatus::RenewalDue, PrescriptionStatus::Active, PrescriptionStatus::Completed, PrescriptionStatus::Canceled],
            PrescriptionStatus::Completed, PrescriptionStatus::Canceled => [$current],
        };

        if (! in_array($next, $allowed, true)) {
            throw ValidationException::withMessages(['status' => ['La receta no puede cambiar a ese estado desde su estado actual.']]);
        }
    }

    private function assertLaboratoryStatusTransition(LaboratoryOrderStatus $current, LaboratoryOrderStatus $next): void
    {
        $allowed = match ($current) {
            LaboratoryOrderStatus::Requested => [LaboratoryOrderStatus::Requested, LaboratoryOrderStatus::InProgress, LaboratoryOrderStatus::Canceled],
            LaboratoryOrderStatus::InProgress => [LaboratoryOrderStatus::InProgress, LaboratoryOrderStatus::Ready, LaboratoryOrderStatus::Canceled],
            LaboratoryOrderStatus::Ready => [LaboratoryOrderStatus::Ready, LaboratoryOrderStatus::Reviewed],
            LaboratoryOrderStatus::Reviewed, LaboratoryOrderStatus::Canceled => [$current],
        };

        if (! in_array($next, $allowed, true)) {
            throw ValidationException::withMessages(['status' => ['El resultado no puede cambiar a ese estado desde su estado actual.']]);
        }
    }

    private function assertTeleconsultationStatusTransition(TeleconsultationStatus $current, TeleconsultationStatus $next): void
    {
        $allowed = match ($current) {
            TeleconsultationStatus::Scheduled => [TeleconsultationStatus::Scheduled, TeleconsultationStatus::Waiting, TeleconsultationStatus::Canceled],
            TeleconsultationStatus::Waiting => [TeleconsultationStatus::Waiting, TeleconsultationStatus::InProgress, TeleconsultationStatus::Canceled],
            TeleconsultationStatus::InProgress => [TeleconsultationStatus::InProgress, TeleconsultationStatus::Completed, TeleconsultationStatus::Canceled],
            TeleconsultationStatus::Completed, TeleconsultationStatus::Canceled => [$current],
        };

        if (! in_array($next, $allowed, true)) {
            throw ValidationException::withMessages(['status' => ['La teleconsulta no puede cambiar a ese estado desde su estado actual.']]);
        }
    }

    /** @return array<string, mixed> */
    private function serializePrescription(DoctorPrescription $prescription): array
    {
        return [
            'id' => $prescription->id,
            'clinic' => $prescription->clinic?->only(['id', 'name']),
            'patient' => $this->serializePatient($prescription->patientProfile),
            'consultation_id' => $prescription->consultation_id,
            'medication' => $prescription->medication,
            'dosage' => $prescription->dosage,
            'frequency' => $prescription->frequency,
            'duration_days' => $prescription->duration_days,
            'status' => $prescription->status->value,
            'status_label' => $prescription->status->label(),
            'issued_at' => $prescription->issued_at?->toIso8601String(),
            'expires_at' => $prescription->expires_at?->toIso8601String(),
            'notes' => $prescription->notes,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeLaboratoryOrder(LaboratoryOrder $order): array
    {
        return [
            'id' => $order->id,
            'clinic' => $order->clinic?->only(['id', 'name']),
            'patient' => $this->serializePatient($order->patientProfile),
            'consultation_id' => $order->consultation_id,
            'study_name' => $order->study_name,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'priority' => $order->priority,
            'is_critical' => $order->is_critical,
            'ordered_at' => $order->ordered_at?->toIso8601String(),
            'result_at' => $order->result_at?->toIso8601String(),
            'result_summary' => $order->result_summary,
            'notes' => $order->notes,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeTeleconsultation(Teleconsultation $session): array
    {
        return [
            'id' => $session->id,
            'clinic' => $session->clinic?->only(['id', 'name']),
            'patient' => $this->serializePatient($session->patientProfile),
            'appointment_id' => $session->appointment_id,
            'consultation_id' => $session->consultation_id,
            'status' => $session->status->value,
            'status_label' => $session->status->label(),
            'scheduled_at' => $session->scheduled_at?->toIso8601String(),
            'started_at' => $session->started_at?->toIso8601String(),
            'ended_at' => $session->ended_at?->toIso8601String(),
            'notes' => $session->notes,
            'connection' => [
                'status' => 'not_configured',
                'message' => 'No hay un proveedor de video configurado para esta práctica.',
                'joinable' => false,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function serializeMessage(DoctorMessage $message, User $viewer): array
    {
        return [
            'id' => $message->id,
            'patient' => $message->patientProfile === null ? null : $this->serializePatient($message->patientProfile),
            'sender' => $message->sender?->only(['id', 'name']),
            'recipient' => $message->recipient?->only(['id', 'name']),
            'body' => $message->body,
            'is_received' => $message->recipient_user_id === $viewer->id,
            'read_at' => $message->read_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializePatient(?PatientProfile $patient): array
    {
        return [
            'id' => $patient?->id,
            'patient_code' => $patient === null ? null : 'PAC-'.str_pad((string) $patient->id, 6, '0', STR_PAD_LEFT),
            'name' => $patient?->user?->name,
        ];
    }
}
