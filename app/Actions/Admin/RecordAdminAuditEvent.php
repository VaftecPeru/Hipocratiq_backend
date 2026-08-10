<?php

namespace App\Actions\Admin;

use App\Models\AdminAuditEvent;
use App\Models\Clinic;
use App\Models\User;
use Illuminate\Http\Request;

final class RecordAdminAuditEvent
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function handle(
        User $actor,
        Request $request,
        string $module,
        string $action,
        ?string $resourceType = null,
        int|string|null $resourceId = null,
        ?string $resourceLabel = null,
        ?Clinic $clinic = null,
        ?string $countryCode = null,
        array $metadata = [],
        bool $critical = false,
    ): AdminAuditEvent {
        return AdminAuditEvent::create([
            'actor_user_id' => $actor->getKey(),
            'clinic_id' => $clinic?->getKey(),
            'country_code' => $countryCode ?? $clinic?->country_code ?? $actor->country_code,
            'module' => $module,
            'action' => $action,
            'is_critical' => $critical,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId === null ? null : (string) $resourceId,
            'resource_label' => $resourceLabel,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => $request->headers->get('X-Request-ID'),
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }
}
