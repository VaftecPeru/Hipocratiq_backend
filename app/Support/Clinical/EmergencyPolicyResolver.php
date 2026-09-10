<?php

namespace App\Support\Clinical;

use App\Models\Clinic;
use App\Models\PlatformSetting;

final class EmergencyPolicyResolver
{
    /** @return array<string, mixed> */
    public function defaultPolicy(): array
    {
        return [
            'enabled' => true,
            'visibility' => 'reception_doctors_admins',
            'label' => 'Emergencia',
            'color' => '#EF2F3F',
            'inheritance' => 'workflow_and_responsibles',
            'default_channel' => 'priority_push',
            'escalation_minutes' => 3,
        ];
    }

    /** @return list<array{role: string, channel: string}> */
    public function defaultResponsibles(): array
    {
        return [
            ['role' => 'Administrador de clínica', 'channel' => 'Resumen de evento y auditoría'],
            ['role' => 'Jefe de guardia', 'channel' => 'Push prioritario'],
            ['role' => 'Recepción', 'channel' => 'Alerta interna inmediata'],
        ];
    }

    /** @return array<string, mixed> */
    public function platformPolicy(?PlatformSetting $settings): array
    {
        return array_replace(
            $this->defaultPolicy(),
            is_array($settings?->emergency_policy) ? $settings->emergency_policy : [],
        );
    }

    /** @return list<array{role: string, channel: string}> */
    public function platformResponsibles(?PlatformSetting $settings): array
    {
        $responsibles = $settings?->emergency_responsibles;

        return is_array($responsibles) && $responsibles !== []
            ? array_values($responsibles)
            : $this->defaultResponsibles();
    }

    /** @return array<string, mixed> */
    public function localPolicy(Clinic $clinic): array
    {
        $stored = is_array($clinic->preferences) ? $clinic->preferences : [];
        $emergency = is_array($stored['emergency'] ?? null) ? $stored['emergency'] : [];
        $policy = array_replace($this->defaultPolicy(), $emergency);
        $responsibles = $emergency['responsibles'] ?? null;

        $policy['responsibles'] = is_array($responsibles) && $responsibles !== []
            ? array_values($responsibles)
            : $this->defaultResponsibles();

        return $policy;
    }

    /** @return array<string, mixed> */
    public function resolve(Clinic $clinic, ?PlatformSetting $settings = null): array
    {
        $settings ??= PlatformSetting::query()->find(1);
        $local = $this->localPolicy($clinic);
        $publication = $this->publication($settings);
        $effective = $local;

        if ($publication !== null) {
            $effective = array_replace($effective, $publication['policy']);
            $effective['responsibles'] = $publication['responsibles'];
        }

        return [
            'local' => $local,
            'effective' => $effective,
            'is_overridden' => $publication !== null,
            'global_override' => $publication,
        ];
    }

    /** @return array<string, mixed>|null */
    public function publication(?PlatformSetting $settings): ?array
    {
        $publication = $settings?->emergency_publication;

        if (! is_array($publication) || ! is_array($publication['policy'] ?? null)) {
            return null;
        }

        $policy = array_replace($this->defaultPolicy(), $publication['policy']);
        $responsibles = $publication['responsibles'] ?? null;

        return [
            'scope' => 'all',
            'force' => (bool) ($publication['force'] ?? true),
            'note' => $publication['note'] ?? null,
            'published_at' => $publication['published_at'] ?? $settings?->emergency_published_at?->toIso8601String(),
            'published_by' => $publication['published_by'] ?? null,
            'policy' => $policy,
            'responsibles' => is_array($responsibles) && $responsibles !== []
                ? array_values($responsibles)
                : $this->defaultResponsibles(),
        ];
    }
}
