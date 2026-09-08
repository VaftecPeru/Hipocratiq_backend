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
use App\Support\Clinical\EmergencyPolicyResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminEmergencyController extends Controller
{
    public function __construct(private readonly EmergencyPolicyResolver $emergencyPolicies) {}

    public function show(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->serialize($this->settings())]);
    }

    public function update(
        AdminEmergencyPolicyUpdateRequest $request,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        $settings = DB::transaction(function () use ($request, $audit): PlatformSetting {
            $settings = $this->lockedSettings();
            $before = $this->serialize($settings);
            $data = $request->validated();
            $responsibles = array_values($data['responsibles']);
            unset($data['responsibles']);
            $policy = array_replace($this->emergencyPolicies->platformPolicy($settings), $data);

            $settings->forceFill([
                'emergency_policy' => $policy,
                'emergency_responsibles' => $responsibles,
            ])->save();
            $settings->refresh();
            $audit->handle(
                $request->user(),
                $request,
                'emergency',
                'emergency_policy_updated',
                PlatformSetting::class,
                $settings->id,
                'Política global de emergencia',
                metadata: [
                    'before' => [
                        'policy' => $before['policy'],
                        'responsibles' => $before['responsibles'],
                    ],
                    'after' => [
                        'policy' => $policy,
                        'responsibles' => $responsibles,
                    ],
                ],
                critical: true,
            );

            return $settings;
        });

        return response()->json(['success' => true, 'data' => $this->serialize($settings)]);
    }

    public function publish(
        AdminEmergencyPublishRequest $request,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        [$settings, $affected] = DB::transaction(function () use ($request, $audit): array {
            $settings = $this->lockedSettings();
            $data = $request->validated();
            $publishedAt = now();
            $policy = $this->emergencyPolicies->platformPolicy($settings);
            $responsibles = $this->emergencyPolicies->platformResponsibles($settings);
            $actor = $request->user();
            $publication = [
                'scope' => 'all',
                'force' => true,
                'note' => $data['note'] ?? null,
                'published_at' => $publishedAt->toIso8601String(),
                'published_by' => [
                    'id' => $actor?->getKey(),
                    'name' => $actor?->name,
                    'email' => $actor?->email,
                ],
                'policy' => $policy,
                'responsibles' => $responsibles,
            ];
            $affected = Clinic::query()->count();

            $settings->forceFill([
                'emergency_published_at' => $publishedAt,
                'emergency_publication' => $publication,
            ])->save();
            $settings->refresh();
            $audit->handle(
                $request->user(),
                $request,
                'emergency',
                'emergency_policy_published',
                PlatformSetting::class,
                $settings->id,
                'Política global de emergencia',
                metadata: [
                    'scope' => 'all',
                    'force' => true,
                    'requested_force' => (bool) ($data['force'] ?? false),
                    'note' => $data['note'] ?? null,
                    'affected_clinics' => $affected,
                    'override' => 'mandatory_global',
                    'policy' => $policy,
                    'responsibles' => $responsibles,
                    'published_by' => $publication['published_by'],
                    'published_at' => $publishedAt->toIso8601String(),
                ],
                critical: true,
            );

            return [$settings, $affected];
        });

        return response()->json(['success' => true, 'data' => [
            'published_at' => $settings->emergency_published_at?->toIso8601String(),
            'affected_clinics' => $affected,
            'publication' => $this->emergencyPolicies->publication($settings),
        ]]);
    }

    private function settings(): PlatformSetting
    {
        return PlatformSetting::query()->find(1) ?? new PlatformSetting([
            'default_country_code' => 'MX',
            'enabled_country_codes' => ['MX'],
        ]);
    }

    private function lockedSettings(): PlatformSetting
    {
        $settings = PlatformSetting::query()->firstOrCreate(
            ['id' => 1],
            ['default_country_code' => 'MX', 'enabled_country_codes' => ['MX']],
        );

        return PlatformSetting::query()->lockForUpdate()->findOrFail($settings->id);
    }

    /** @return array<string, mixed> */
    private function serialize(PlatformSetting $settings): array
    {
        $policy = $this->emergencyPolicies->platformPolicy($settings);
        $responsibles = $this->emergencyPolicies->platformResponsibles($settings);
        $activeClinics = Clinic::query()->where('status', ClinicStatus::Active->value)->count();

        return [
            'policy' => $policy,
            'responsibles' => $responsibles,
            'published_at' => $settings->emergency_published_at?->toIso8601String(),
            'publication' => $this->emergencyPolicies->publication($settings),
            'stats' => [
                'active_clinics' => $activeClinics,
                'enabled_clinics' => ($policy['enabled'] ?? false) ? $activeClinics : 0,
                'policy_changes_today' => AdminAuditEvent::query()->where('module', 'emergency')->where('occurred_at', '>=', now()->startOfDay())->count(),
            ],
        ];
    }
}
