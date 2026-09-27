<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Admin\RecordAdminAuditEvent;
use App\Enums\PatientConsentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PatientConsentStoreRequest;
use App\Http\Resources\Api\V1\PatientConsentResource;
use App\Models\PatientConsent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PatientConsentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $patient = $request->user()->patientProfile()->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => PatientConsentResource::collection(
                $patient->consents()->latest('id')->get(),
            ),
        ]);
    }

    public function store(PatientConsentStoreRequest $request): JsonResponse
    {
        $user = $request->user();
        $patient = $user->patientProfile()->firstOrFail();
        $data = $request->validated();

        $existing = $patient->consents()
            ->where('scope', $data['scope'])
            ->where('policy_version', $data['policy_version'])
            ->where('status', PatientConsentStatus::Granted->value)
            ->where(function ($query): void {
                $query->whereNull('effective_until')->orWhere('effective_until', '>', now());
            })
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return (new PatientConsentResource($existing))->response();
        }

        $consent = $patient->consents()->create([
            'clinic_id' => $patient->clinic_id,
            'granted_by_user_id' => $user->id,
            'scope' => $data['scope'],
            'policy_version' => $data['policy_version'],
            'status' => PatientConsentStatus::Granted,
            'source' => 'digital',
            'effective_from' => now(),
            'effective_until' => $data['effective_until'] ?? null,
        ]);

        app(RecordAdminAuditEvent::class)->handle(
            actor: $user,
            request: $request,
            module: 'clinical-consent',
            action: 'consent.granted',
            resourceType: 'patient_consent',
            resourceId: $consent->id,
            clinic: $patient->clinic,
            metadata: [
                'patient_profile_id' => $patient->id,
                'scope' => $consent->scope->value,
                'policy_version' => $consent->policy_version,
            ],
            critical: true,
        );

        return (new PatientConsentResource($consent))->response()->setStatusCode(201);
    }

    public function revoke(Request $request, PatientConsent $consent): JsonResponse
    {
        $user = $request->user();
        $patient = $user->patientProfile()->firstOrFail();

        abort_unless($consent->patient_profile_id === $patient->id, 404);

        if ($consent->status !== PatientConsentStatus::Revoked) {
            $consent->update([
                'status' => PatientConsentStatus::Revoked,
                'revoked_by_user_id' => $user->id,
                'revoked_at' => now(),
            ]);

            app(RecordAdminAuditEvent::class)->handle(
                actor: $user,
                request: $request,
                module: 'clinical-consent',
                action: 'consent.revoked',
                resourceType: 'patient_consent',
                resourceId: $consent->id,
                clinic: $patient->clinic,
                metadata: [
                    'patient_profile_id' => $patient->id,
                    'scope' => $consent->scope->value,
                    'policy_version' => $consent->policy_version,
                ],
                critical: true,
            );
        }

        return (new PatientConsentResource($consent->fresh()))->response();
    }
}
