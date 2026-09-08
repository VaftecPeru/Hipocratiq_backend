<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Admin\RecordAdminAuditEvent;
use App\Enums\SystemIncidentSeverity;
use App\Enums\SystemIncidentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AdminSystemIncidentStoreRequest;
use App\Http\Requests\Api\V1\AdminSystemIncidentUpdateRequest;
use App\Models\SystemIncident;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminSystemIncidentController extends Controller
{
    public function store(
        AdminSystemIncidentStoreRequest $request,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        $data = $request->validated();
        $incident = SystemIncident::query()->create([
            ...$data,
            'status' => SystemIncidentStatus::Investigating,
            'created_by_user_id' => $request->user()->getKey(),
            'started_at' => now(),
        ]);

        $audit->handle(
            $request->user(),
            $request,
            'monitoring',
            'incident_created',
            SystemIncident::class,
            $incident->id,
            $incident->title,
            countryCode: $incident->country_code,
            metadata: [
                'severity' => $incident->severity->value,
                'status' => $incident->status->value,
                'service_key' => $incident->service_key,
            ],
            critical: $incident->severity !== SystemIncidentSeverity::Minor,
        );

        return response()->json(['success' => true, 'data' => $this->serialize($incident)], 201);
    }

    public function update(
        AdminSystemIncidentUpdateRequest $request,
        SystemIncident $systemIncident,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        $data = $request->validated();
        $nextStatus = SystemIncidentStatus::from($data['status']);
        $beforeStatus = $systemIncident->status;
        $this->assertTransitionAllowed($beforeStatus, $nextStatus);

        DB::transaction(function () use ($request, $systemIncident, $nextStatus): void {
            $systemIncident->update([
                'status' => $nextStatus,
                'resolved_at' => $nextStatus === SystemIncidentStatus::Resolved ? now() : null,
                'resolved_by_user_id' => $nextStatus === SystemIncidentStatus::Resolved ? $request->user()->getKey() : null,
            ]);
        });

        $audit->handle(
            $request->user(),
            $request,
            'monitoring',
            $nextStatus === SystemIncidentStatus::Resolved ? 'incident_resolved' : 'incident_status_updated',
            SystemIncident::class,
            $systemIncident->id,
            $systemIncident->title,
            countryCode: $systemIncident->country_code,
            metadata: [
                'before' => ['status' => $beforeStatus->value],
                'after' => ['status' => $nextStatus->value],
                'note' => $data['note'] ?? null,
                'severity' => $systemIncident->severity->value,
                'service_key' => $systemIncident->service_key,
            ],
            critical: $systemIncident->severity !== SystemIncidentSeverity::Minor,
        );

        return response()->json(['success' => true, 'data' => $this->serialize($systemIncident->fresh())]);
    }

    private function assertTransitionAllowed(SystemIncidentStatus $current, SystemIncidentStatus $next): void
    {
        if ($current === $next) {
            return;
        }

        $allowed = match ($current) {
            SystemIncidentStatus::Investigating => [SystemIncidentStatus::Identified, SystemIncidentStatus::Resolved],
            SystemIncidentStatus::Identified => [SystemIncidentStatus::Monitoring, SystemIncidentStatus::Resolved],
            SystemIncidentStatus::Monitoring => [SystemIncidentStatus::Investigating, SystemIncidentStatus::Resolved],
            SystemIncidentStatus::Resolved => [SystemIncidentStatus::Investigating],
        };

        if (! in_array($next, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["No se puede pasar de {$current->value} a {$next->value}."],
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function serialize(SystemIncident $incident): array
    {
        return [
            'id' => $incident->id,
            'title' => $incident->title,
            'description' => $incident->description,
            'severity' => $incident->severity->value,
            'status' => $incident->status->value,
            'service_key' => $incident->service_key,
            'country_code' => $incident->country_code,
            'started_at' => $incident->started_at?->toIso8601String(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
        ];
    }
}
