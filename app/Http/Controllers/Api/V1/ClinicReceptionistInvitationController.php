<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Admin\RecordAdminAuditEvent;
use App\Actions\Clinic\CreateReceptionistInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ClinicReceptionistInvitationRequest;
use App\Models\Clinic;
use App\Models\ReceptionistInvitation;
use Illuminate\Http\JsonResponse;

class ClinicReceptionistInvitationController extends Controller
{
    public function store(
        ClinicReceptionistInvitationRequest $request,
        CreateReceptionistInvitation $createInvitation,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        $clinic = $request->attributes->get('clinic');
        abort_unless($clinic instanceof Clinic, 404);
        $result = $createInvitation->handle($request->validated(), $clinic, $request->user());
        $invitation = $result['invitation'];

        $audit->handle(
            $request->user(),
            $request,
            'clinic_team',
            'receptionist_invited',
            ReceptionistInvitation::class,
            $invitation->id,
            $invitation->name,
            clinic: $clinic,
            countryCode: $invitation->country_code,
            metadata: ['role' => 'receptionist', 'expires_at' => $invitation->expires_at?->toIso8601String()],
        );

        return response()->json([
            'success' => true,
            'data' => [
                'invitation_token' => $result['token'],
                'expires_at' => $invitation->expires_at,
                'next_step' => 'activate',
            ],
        ], 201);
    }
}
