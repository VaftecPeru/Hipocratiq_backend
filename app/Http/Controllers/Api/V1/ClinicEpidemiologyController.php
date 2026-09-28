<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Admin\RecordAdminAuditEvent;
use App\Contracts\EpidemiologyAnalyzer;
use App\Enums\PatientConsentScope;
use App\Enums\PatientConsentStatus;
use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\DoctorPrescription;
use App\Models\LaboratoryOrder;
use App\Models\PatientConsent;
use App\Models\Teleconsultation;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Throwable;

class ClinicEpidemiologyController extends Controller
{
    public function __construct(private EpidemiologyAnalyzer $analyzer) {}

    public function show(Request $request): JsonResponse
    {
        $clinic = $this->clinic($request);
        $now = CarbonImmutable::now();
        $from = $now->subDays(90)->startOfDay();

        $appointments = $clinic->appointments()
            ->whereBetween('scheduled_at', [$from, $now])
            ->get(['scheduled_at', 'patient_profile_id']);
        $consultations = $clinic->consultations()
            ->whereBetween('created_at', [$from, $now])
            ->get(['created_at', 'patient_profile_id']);
        $laboratoryOrders = LaboratoryOrder::query()
            ->where('clinic_id', $clinic->id)
            ->whereBetween('ordered_at', [$from, $now])
            ->get(['ordered_at', 'patient_profile_id']);
        $teleconsultations = Teleconsultation::query()
            ->where('clinic_id', $clinic->id)
            ->whereBetween('scheduled_at', [$from, $now])
            ->get(['scheduled_at', 'patient_profile_id']);
        $prescriptions = DoctorPrescription::query()
            ->where('clinic_id', $clinic->id)
            ->whereBetween('issued_at', [$from, $now])
            ->get(['issued_at', 'patient_profile_id']);
        $highRiskPatientIds = $clinic->patientIntakes()
            ->whereBetween('created_at', [$from, $now])
            ->whereIn('triage_priority', ['urgent', 'emergency'])
            ->pluck('patient_profile_id')
            ->filter()
            ->unique()
            ->values();

        $monitoredPatients = $this->monitoredPatientIds(
            $appointments,
            $consultations,
            $laboratoryOrders,
            $teleconsultations,
            $prescriptions,
        );
        $trend = $this->trend($now, $appointments, $consultations, $laboratoryOrders);

        $policyVersion = (string) config('clinical.consent_policy_version');
        $minimumCohortSize = max(1, (int) config('clinical.epidemiology.minimum_external_cohort_size', 10));
        $consentedPatientIds = $this->consentedPatientIds($clinic, $now, $policyVersion);

        $analysisAppointments = $this->forPatients($appointments, $consentedPatientIds);
        $analysisConsultations = $this->forPatients($consultations, $consentedPatientIds);
        $analysisLaboratoryOrders = $this->forPatients($laboratoryOrders, $consentedPatientIds);
        $analysisTeleconsultations = $this->forPatients($teleconsultations, $consentedPatientIds);
        $analysisPrescriptions = $this->forPatients($prescriptions, $consentedPatientIds);
        $analysisMonitoredPatients = $this->monitoredPatientIds(
            $analysisAppointments,
            $analysisConsultations,
            $analysisLaboratoryOrders,
            $analysisTeleconsultations,
            $analysisPrescriptions,
        );
        $analysisHighRiskCases = $highRiskPatientIds->intersect($consentedPatientIds)->count();
        $analysisTrend = $this->trend(
            $now,
            $analysisAppointments,
            $analysisConsultations,
            $analysisLaboratoryOrders,
        );

        $analysis = [
            'trend' => 'not_configured',
            'forecasts' => [],
            'alerts' => [],
            'recommendations' => [],
            'summary' => null,
        ];
        $analysisAvailable = false;
        $analysisMessage = 'No hay un motor epidemiológico configurado. Se muestran únicamente eventos clínicos internos.';
        $cohortStatus = 'not_used';

        if ($this->analyzer->configured()) {
            if ($analysisMonitoredPatients->count() < $minimumCohortSize) {
                $cohortStatus = 'insufficient';
                $analysisMessage = sprintf(
                    'El motor epidemiológico está configurado, pero la cohorte con consentimiento activo no alcanza el mínimo de privacidad (%d pacientes).',
                    $minimumCohortSize,
                );
            } else {
                $cohortStatus = 'eligible';

                try {
                    $analysis = $this->analyzer->analyze([
                        'period' => [
                            'from' => $from->toDateString(),
                            'to' => $now->toDateString(),
                            'days' => 90,
                        ],
                        'metrics' => [
                            'monitored_patients' => $analysisMonitoredPatients->count(),
                            'high_risk_cases' => $analysisHighRiskCases,
                            'appointments' => $analysisAppointments->count(),
                            'consultations' => $analysisConsultations->count(),
                            'laboratory_orders' => $analysisLaboratoryOrders->count(),
                            'teleconsultations' => $analysisTeleconsultations->count(),
                            'prescriptions' => $analysisPrescriptions->count(),
                        ],
                        'monthly_trend' => $analysisTrend,
                    ]);
                    $analysisAvailable = true;
                    $analysisMessage = is_string($analysis['summary'] ?? null)
                        ? $analysis['summary']
                        : 'Análisis epidemiológico agregado disponible para revisión humana.';

                    $this->recordExternalAnalysisAudit(
                        request: $request,
                        clinic: $clinic,
                        action: 'epidemiology.analysis.completed',
                        policyVersion: $policyVersion,
                        cohortSize: $analysisMonitoredPatients->count(),
                        minimumCohortSize: $minimumCohortSize,
                        provider: $this->analyzer->provider(),
                    );
                } catch (Throwable) {
                    $analysisMessage = 'El motor epidemiológico está configurado, pero no respondió. Se muestran datos internos sin predicción.';

                    $this->recordExternalAnalysisAudit(
                        request: $request,
                        clinic: $clinic,
                        action: 'epidemiology.analysis.failed',
                        policyVersion: $policyVersion,
                        cohortSize: $analysisMonitoredPatients->count(),
                        minimumCohortSize: $minimumCohortSize,
                        provider: $this->analyzer->provider(),
                        critical: true,
                    );
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'generated_at' => $now->toIso8601String(),
                'period' => [
                    'from' => $from->toDateString(),
                    'to' => $now->toDateString(),
                    'days' => 90,
                ],
                'analysis_available' => $analysisAvailable,
                'provider' => $this->analyzer->provider(),
                'message' => $analysisMessage,
                'analysis_cohort' => [
                    'status' => $cohortStatus,
                    'policy_version' => $policyVersion,
                    'consented_patients' => $consentedPatientIds->count(),
                    'monitored_patients' => $analysisMonitoredPatients->count(),
                    'minimum_required' => $minimumCohortSize,
                ],
                'metrics' => [
                    'monitored_patients' => $monitoredPatients->count(),
                    'active_alerts' => count($analysis['alerts'] ?? []),
                    'high_risk_cases' => $highRiskPatientIds->count(),
                    'trend' => $analysis['trend'] ?? 'not_configured',
                    'appointments' => $appointments->count(),
                    'consultations' => $consultations->count(),
                    'laboratory_orders' => $laboratoryOrders->count(),
                    'teleconsultations' => $teleconsultations->count(),
                    'prescriptions' => $prescriptions->count(),
                ],
                'trend' => $trend,
                'forecasts' => $analysis['forecasts'] ?? [],
                'alerts' => $analysis['alerts'] ?? [],
                'recommendations' => $analysis['recommendations'] ?? [],
                'map' => [
                    'status' => 'not_configured',
                    'message' => 'No hay datos geográficos ni una fuente territorial configurada para esta clínica.',
                ],
                'sources' => [
                    'clinical_history' => $this->source($consultations->count(), 'Historia clínica interna'),
                    'laboratory' => $this->source($laboratoryOrders->count(), 'Órdenes de laboratorio internas'),
                    'telemedicine' => $this->source($teleconsultations->count(), 'Teleconsultas internas'),
                    'prescriptions' => $this->source($prescriptions->count(), 'Recetas internas'),
                ],
            ],
        ]);
    }

    private function consentedPatientIds(Clinic $clinic, CarbonImmutable $now, string $policyVersion): Collection
    {
        return PatientConsent::query()
            ->where('clinic_id', $clinic->id)
            ->where('scope', PatientConsentScope::EpidemiologyAnonymized->value)
            ->where('policy_version', $policyVersion)
            ->where('status', PatientConsentStatus::Granted->value)
            ->whereNull('revoked_at')
            ->where('effective_from', '<=', $now)
            ->where(function ($query) use ($now): void {
                $query->whereNull('effective_until')->orWhere('effective_until', '>', $now);
            })
            ->pluck('patient_profile_id')
            ->filter()
            ->unique()
            ->values();
    }

    private function forPatients(Collection $rows, Collection $patientIds): Collection
    {
        if ($patientIds->isEmpty()) {
            return collect();
        }

        $lookup = array_fill_keys(
            $patientIds->map(static fn ($id): string => (string) $id)->all(),
            true,
        );

        return $rows
            ->filter(static fn ($row): bool => isset($lookup[(string) $row->patient_profile_id]))
            ->values();
    }

    private function monitoredPatientIds(Collection ...$collections): Collection
    {
        return collect($collections)
            ->flatMap(static fn (Collection $items): Collection => $items->pluck('patient_profile_id'))
            ->filter()
            ->unique()
            ->values();
    }

    private function trend(
        CarbonImmutable $now,
        Collection $appointments,
        Collection $consultations,
        Collection $laboratoryOrders,
    ): array {
        $trend = [];
        $month = $now->startOfMonth()->subMonths(2);

        for ($index = 0; $index < 3; $index++) {
            $key = $month->format('Y-m');
            $trend[] = [
                'month' => $key,
                'label' => $month->format('m/Y'),
                'appointments' => $appointments->filter(fn ($row): bool => $row->scheduled_at?->format('Y-m') === $key)->count(),
                'consultations' => $consultations->filter(fn ($row): bool => $row->created_at?->format('Y-m') === $key)->count(),
                'laboratory_orders' => $laboratoryOrders->filter(fn ($row): bool => $row->ordered_at?->format('Y-m') === $key)->count(),
            ];
            $month = $month->addMonth();
        }

        return $trend;
    }

    private function recordExternalAnalysisAudit(
        Request $request,
        Clinic $clinic,
        string $action,
        string $policyVersion,
        int $cohortSize,
        int $minimumCohortSize,
        string $provider,
        bool $critical = false,
    ): void {
        $actor = $request->user();

        if ($actor === null) {
            return;
        }

        app(RecordAdminAuditEvent::class)->handle(
            actor: $actor,
            request: $request,
            module: 'epidemiology',
            action: $action,
            resourceType: 'clinic',
            resourceId: $clinic->id,
            resourceLabel: $clinic->name,
            clinic: $clinic,
            metadata: [
                'provider' => $provider,
                'policy_version' => $policyVersion,
                'cohort_size' => $cohortSize,
                'minimum_cohort_size' => $minimumCohortSize,
                'payload_classification' => 'aggregate_only',
            ],
            critical: $critical,
        );
    }

    private function source(int $count, string $label): array
    {
        return [
            'status' => $count > 0 ? 'available' : 'no_data',
            'label' => $label,
            'count' => $count,
        ];
    }

    private function clinic(Request $request): Clinic
    {
        return $request->attributes->get('clinic');
    }
}
