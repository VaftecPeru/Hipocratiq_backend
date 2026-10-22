<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RequestDiagnosticProviderRegistration;
use App\Models\DiagnosticProvider;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;

class DiagnosticProviderRegistrationController extends Controller
{
    public function request(RequestDiagnosticProviderRegistration $request): JsonResponse
    {
        $data = $request->validated();

        DiagnosticProvider::query()->create([
            'provider_type' => $data['provider_type'],
            'name' => $data['name'],
            'tax_id' => $data['tax_id'],
            'country_code' => $data['country_code'],
            'contact_name' => $data['contact_name'],
            'contact_email' => $data['contact_email'],
            'contact_phone' => PhoneNumber::from($data['contact_phone'], $data['country_code'])->value,
        ]);

        // No emitir tokens, sesiones, roles, planes ni accesos a expedientes.
        // El alta de una organización requiere verificación y aprobación posteriores.
        return response()->json([
            'success' => true,
            'data' => [
                'next_step' => 'await_verification',
                'status' => 'pending',
            ],
            'message' => 'Solicitud recibida. Validaremos la identidad y afiliación antes de activar accesos.',
        ], 202);
    }
}
