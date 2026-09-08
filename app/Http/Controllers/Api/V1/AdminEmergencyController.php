<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Admin\RecordAdminAuditEvent;
use App\Enums\ClinicStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AdminEmergencyPolicyUpdateRequest;
use App\Http\Requests\Api\V1\AdminEmergencyPublishRequest;
use App\Models\AdminAuditEvent;
use App\Models\Clinic;
use App\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;

class AdminEmergencyController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->serialize($this->settings())]);
    }

    public function update(
        AdminEmergencyPolicyUpdateRequest $request,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        $settings = $this->settings();
        $before = $this->serialize($settings);
        $data = $request->validated();
        $responsibles = $data['responsibles'];
        unset($data['responsibles']);
        $settings->update(['emergency_policy' => $data, 'emergency_responsibles' => $responsibles]);
        $settings->refresh();
        $audit->handle($request->user(), $request, 'emergency', 'emergency_policy_updated', PlatformSetting::class, $settings->id, 'Política global de emergencia', metadata: ['before' => $before['policy'], 'after' => $data], critical: true);

        return response()->json(['success' => true, 'data' => $this->serialize($settings)]);
    }

    public function publish(
        AdminEmergencyPublishRequest $request,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        $settings = $this->settings();
        $data = $request->validated();
        $settings->update(['emergency_published_at' => now()]);
        $affected = Clinic::query()
            ->where('status', ClinicStatus::Active->value)
            ->when($data['scope'] !== 'all', fn ($query) => $query->where('country_code', $data['scope']))
            ->count();
        $audit->handle($request->user(), $request, 'emergency', 'emergency_policy_published', PlatformSetting::class, $settings->id, 'Política global de emergencia', countryCode: $data['scope'] === 'all' ? null : $data['scope'], metadata: ['scope' => $data['scope'], 'force' => (bool) ($data['force'] ?? false), 'note' => $data['note'] ?? null, 'affected_clinics' => $affected], critical: true);

        return response()->json(['success' => true, 'data' => ['published_at' => $settings->emergency_published_at?->toIso8601String(), 'affected_clinics' => $affected]]);
    }

    private function settings(): PlatformSetting
    {
        return PlatformSetting::query()->firstOrCreate(['id' => 1], ['default_country_code' => 'MX', 'enabled_country_codes' => ['MX']]);
    }

    /** @return array<string, mixed> */
    private function serialize(PlatformSetting $settings): array
    {
        $policy = $settings->emergency_policy ?? [
            'enabled' => true,
            'visibility' => 'reception_doctors_admins',
            'color' => '#EF2F3F',
            'inheritance' => 'workflow_and_responsibles',
            'default_channel' => 'priority_push',
            'escalation_minutes' => 3,
        ];
        $responsibles = $settings->emergency_responsibles ?? [
            ['role' => 'Administrador de clínica', 'channel' => 'Resumen de evento y auditoría'],
            ['role' => 'Jefe de guardia', 'channel' => 'Push prioritario'],
            ['role' => 'Recepción', 'channel' => 'Alerta interna inmediata'],
        ];
        $activeClinics = Clinic::query()->where('status', ClinicStatus::Active->value)->count();

        return [
            'policy' => $policy,
            'responsibles' => $responsibles,
            'published_at' => $settings->emergency_published_at?->toIso8601String(),
            'stats' => [
                'active_clinics' => $activeClinics,
                'enabled_clinics' => ($policy['enabled'] ?? false) ? $activeClinics : 0,
                'policy_changes_today' => AdminAuditEvent::query()->where('module', 'emergency')->where('occurred_at', '>=', now()->startOfDay())->count(),
            ],
        ];
    }
}
