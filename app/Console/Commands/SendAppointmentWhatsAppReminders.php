<?php

namespace App\Console\Commands;

use App\Contracts\WhatsAppSender;
use App\Enums\PatientConsentScope;
use App\Enums\PatientConsentStatus;
use App\Models\Appointment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendAppointmentWhatsAppReminders extends Command
{
    protected $signature = 'appointments:send-whatsapp-reminders';

    protected $description = 'Envía recordatorios WhatsApp idempotentes para próximas citas con consentimiento.';

    public function handle(WhatsAppSender $sender): int
    {
        if (! $sender->configured()) {
            $this->warn('WhatsApp no está configurado; no se enviaron recordatorios.');

            return self::SUCCESS;
        }

        $minutes = max(15, (int) config('integrations.whatsapp.reminder_minutes', 1440));
        $target = now()->addMinutes($minutes);
        $from = $target->copy()->subMinutes(8);
        $to = $target->copy()->addMinutes(8);
        $template = (string) config('integrations.whatsapp.appointment_template');
        $language = (string) config('integrations.whatsapp.appointment_language', 'es_MX');

        if ($template === '') {
            $this->error('WHATSAPP_APPOINTMENT_TEMPLATE no está configurado.');

            return self::FAILURE;
        }

        $appointments = Appointment::query()
            ->with([
                'patientProfile.user',
                'patientProfile.consents' => function ($query): void {
                    $query
                        ->where('scope', PatientConsentScope::WhatsAppNotifications->value)
                        ->where('policy_version', (string) config('clinical.consent_policy_version'))
                        ->where('status', PatientConsentStatus::Granted->value)
                        ->whereNull('revoked_at')
                        ->where('effective_from', '<=', now())
                        ->where(function ($consentQuery): void {
                            $consentQuery
                                ->whereNull('effective_until')
                                ->orWhere('effective_until', '>', now());
                        });
                },
                'doctor',
                'clinic',
            ])
            ->whereBetween('scheduled_at', [$from, $to])
            ->whereIn('status', ['pending', 'confirmed'])
            ->get();

        $failed = 0;

        foreach ($appointments as $appointment) {
            $profile = $appointment->patientProfile;
            $user = $profile?->user;
            $phone = $user?->phone_number;

            if ($profile === null || $profile->consents->isEmpty() || ! is_string($phone) || $phone === '') {
                continue;
            }

            $existing = DB::table('appointment_notification_deliveries')
                ->where('appointment_id', $appointment->id)
                ->where('channel', 'whatsapp')
                ->where('kind', 'appointment_reminder')
                ->first();

            if (($existing->status ?? null) === 'sent') {
                continue;
            }

            DB::table('appointment_notification_deliveries')->updateOrInsert(
                [
                    'appointment_id' => $appointment->id,
                    'channel' => 'whatsapp',
                    'kind' => 'appointment_reminder',
                ],
                [
                    'status' => 'pending',
                    'attempted_at' => now(),
                    'updated_at' => now(),
                    'created_at' => $existing?->created_at ?? now(),
                ],
            );

            try {
                $reference = $sender->sendTemplate($phone, $template, $language, [
                    $user?->name ?? 'Paciente',
                    $appointment->scheduled_at?->format('d/m/Y H:i') ?? '',
                    $appointment->doctor?->name ?? 'Equipo médico',
                    $appointment->clinic?->name ?? 'HIPOCRATIQ',
                ]);

                DB::table('appointment_notification_deliveries')
                    ->where('appointment_id', $appointment->id)
                    ->where('channel', 'whatsapp')
                    ->where('kind', 'appointment_reminder')
                    ->update([
                        'status' => 'sent',
                        'provider_reference' => $reference,
                        'error_message' => null,
                        'sent_at' => now(),
                        'updated_at' => now(),
                    ]);
            } catch (Throwable $exception) {
                $failed++;
                DB::table('appointment_notification_deliveries')
                    ->where('appointment_id', $appointment->id)
                    ->where('channel', 'whatsapp')
                    ->where('kind', 'appointment_reminder')
                    ->update([
                        'status' => 'failed',
                        'error_message' => mb_substr($exception->getMessage(), 0, 1500),
                        'updated_at' => now(),
                    ]);
            }
        }

        $this->info('Recordatorios procesados: '.$appointments->count().'. Fallidos: '.$failed.'.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
