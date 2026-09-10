<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClinicInvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ClinicReceptionistSettingsUpdateRequest;
use App\Models\Clinic;
use App\Models\ClinicReceptionistSetting;
use App\Models\DoctorMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClinicReceptionistSettingsController extends Controller
{
    /** @var array<string, bool|string|null> */
    private const DEFAULT_PREFERENCES = [
        'new_appointments' => true,
        'arrivals' => true,
        'pending_payments' => true,
        'internal_messages' => true,
        'documents' => true,
        'online_reception' => true,
        'whatsapp' => true,
        'auto_print' => true,
        'duplicate_tickets' => false,
        'shift_name' => null,
        'shift_start' => null,
        'shift_end' => null,
        'counter_name' => null,
        'printer_name' => null,
        'paper_size' => null,
    ];

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->payload($this->clinic($request), $request->user()),
        ]);
    }

    public function update(ClinicReceptionistSettingsUpdateRequest $request): JsonResponse
    {
        $clinic = $this->clinic($request);
        $user = $request->user();
        $incomingPreferences = (array) $request->validated('preferences');

        DB::transaction(function () use ($clinic, $user, $incomingPreferences): void {
            $settings = ClinicReceptionistSetting::query()
                ->where('clinic_id', $clinic->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            $preferences = array_replace(
                self::DEFAULT_PREFERENCES,
                (array) $settings?->preferences,
                $incomingPreferences,
            );

            if ($settings === null) {
                ClinicReceptionistSetting::query()->create([
                    'clinic_id' => $clinic->id,
                    'user_id' => $user->id,
                    'preferences' => $preferences,
                ]);

                return;
            }

            $settings->update(['preferences' => $preferences]);
        });

        return response()->json([
            'success' => true,
            'data' => $this->payload($clinic, $user),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(Clinic $clinic, User $user): array
    {
        $settings = ClinicReceptionistSetting::query()
            ->where('clinic_id', $clinic->id)
            ->where('user_id', $user->id)
            ->first();
        $preferences = array_replace(self::DEFAULT_PREFERENCES, (array) $settings?->preferences);
        $now = now();
        $todayAppointments = $clinic->appointments()->whereDate('scheduled_at', $now->toDateString())->count();
        $yesterdayAppointments = $clinic->appointments()->whereDate('scheduled_at', $now->copy()->subDay()->toDateString())->count();
        $appointmentsChangePercent = $yesterdayAppointments > 0
            ? round((($todayAppointments - $yesterdayAppointments) / $yesterdayAppointments) * 100, 1)
            : null;
        $unreadMessages = DoctorMessage::query()
            ->where('clinic_id', $clinic->id)
            ->where('recipient_user_id', $user->id)
            ->whereNull('read_at')
            ->count();
        $pendingPayments = $clinic->invoices()
            ->whereIn('status', [ClinicInvoiceStatus::Issued->value, ClinicInvoiceStatus::PartiallyPaid->value])
            ->count();

        return [
            'profile' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone_number' => $user->phone_number,
                'role' => 'receptionist',
            ],
            'clinic' => [
                'id' => $clinic->id,
                'name' => $clinic->name,
            ],
            'settings' => [
                'persisted' => $settings !== null,
                'updated_at' => $settings?->updated_at?->toIso8601String(),
                'preferences' => $preferences,
            ],
            'operation' => [
                'shift_name' => $preferences['shift_name'],
                'shift_start' => $preferences['shift_start'],
                'shift_end' => $preferences['shift_end'],
                'counter_name' => $preferences['counter_name'],
                'printer_name' => $preferences['printer_name'],
                'paper_size' => $preferences['paper_size'],
                'two_factor' => [
                    'status' => 'not_configured',
                    'message' => 'La autenticación de dos factores no está configurada para esta cuenta.',
                ],
                'cash_register' => [
                    'status' => 'not_configured',
                    'message' => 'No hay una caja operativa conectada para esta clínica.',
                ],
            ],
            'metrics' => [
                'appointments_today' => $todayAppointments,
                'appointments_yesterday' => $yesterdayAppointments,
                'appointments_change_percent' => $appointmentsChangePercent,
                'unread_messages' => $unreadMessages,
                'pending_payments' => $pendingPayments,
                'cash_register' => [
                    'status' => 'not_configured',
                    'opened_at' => null,
                ],
            ],
        ];
    }

    private function clinic(Request $request): Clinic
    {
        return $request->attributes->get('clinic');
    }
}
