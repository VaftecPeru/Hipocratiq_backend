<?php

namespace App\Actions\Clinical;

use App\Models\ClinicalAuditEvent;
use App\Models\Consultation;
use App\Models\User;
use Illuminate\Http\Request;

final class RecordClinicalAuditEvent
{
    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function handle(
        User $actor,
        Request $request,
        Consultation $consultation,
        string $action,
        ?array $before,
        ?array $after,
    ): ClinicalAuditEvent {
        return ClinicalAuditEvent::query()->create([
            'actor_user_id' => $actor->id,
            'clinic_id' => $consultation->clinic_id,
            'patient_profile_id' => $consultation->patient_profile_id,
            'consultation_id' => $consultation->id,
            'action' => $action,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => $request->headers->get('X-Request-ID'),
            'before_payload' => $before,
            'after_payload' => $after,
            'occurred_at' => now(),
        ]);
    }
}
