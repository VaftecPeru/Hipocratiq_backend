<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\DoctorPrescription;
use App\Models\LaboratoryOrder;
use App\Models\Teleconsultation;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ClinicEpidemiologyController extends Controller
{
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
        $highRiskCases = $clinic->patientIntakes()
            ->whereBetween('created_at', [$from, $now])
            ->whereIn('triage_priority', ['urgent', 'emergency'])
            ->pluck('patient_profile_id')
            ->unique()
            ->count();
        $monitoredPatients = collect([
            $appointments->pluck('patient_profile_id'),
            $consultations->pluck('patient_profile_id'),
            $laboratoryOrders->pluck('patient_profile_id'),
            $teleconsultations->pluck('patient_profile_id'),
            $prescriptions->pluck('patient_profile_id'),
        ])->flatten()->filter()->unique()->count();
        $trend = $this->trend($now, $appointments, $consultations, $laboratoryOrders);

        return response()->json([
            'success' => true,
            'data' => [
                'generated_at' => $now->toIso8601String(),
                'period' => [
                    'from' => $from->toDateString(),
                    'to' => $now->toDateString(),
                    'days' => 90,
                ],
                'analysis_available' => false,
                'message' => 'No hay un motor epidemiológico configurado. Se muestran únicamente eventos clínicos internos.',
                'metrics' => [
                    'monitored_patients' => $monitoredPatients,
                    'active_alerts' => 0,
                    'high_risk_cases' => $highRiskCases,
                    'trend' => 'not_configured',
                    'appointments' => $appointments->count(),
                    'consultations' => $consultations->count(),
                    'laboratory_orders' => $laboratoryOrders->count(),
                    'teleconsultations' => $teleconsultations->count(),
                    'prescriptions' => $prescriptions->count(),
                ],
                'trend' => $trend,
                'forecasts' => [],
                'alerts' => [],
                'recommendations' => [],
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

    /** @param Collection<int, mixed> $appointments
     * @param  Collection<int, mixed>  $consultations
     * @param  Collection<int, mixed>  $laboratoryOrders
     * @return array<int, array<string, int|string>>
     */
    private function trend(CarbonImmutable $now, Collection $appointments, Collection $consultations, Collection $laboratoryOrders): array
    {
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

    /** @return array{status: string, label: string, count: int} */
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
