<?php

namespace Tests\Feature;

use App\Contracts\WhatsAppSender;
use App\Enums\PatientConsentScope;
use App\Enums\PatientConsentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\PatientConsent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentWhatsAppReminderConsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_reminder_is_not_sent_without_active_versioned_consent(): void
    {
        $sender = new class implements WhatsAppSender
        {
            public int $calls = 0;

            public function configured(): bool
            {
                return true;
            }

            public function sendTemplate(string $to, string $template, string $language, array $bodyParameters): string
            {
                $this->calls++;

                return 'wamid.test';
            }
        };

        $this->app->instance(WhatsAppSender::class, $sender);
        config()->set('integrations.whatsapp.appointment_template', 'appointment_reminder');
        config()->set('integrations.whatsapp.reminder_minutes', 1440);

        $appointment = $this->appointmentForTomorrow();

        $this->artisan('appointments:send-whatsapp-reminders')->assertSuccessful();

        $this->assertSame(0, $sender->calls);
        $this->assertDatabaseMissing('appointment_notification_deliveries', [
            'appointment_id' => $appointment->id,
            'channel' => 'whatsapp',
            'kind' => 'appointment_reminder',
        ]);
    }

    public function test_reminder_is_sent_when_patient_has_active_versioned_consent(): void
    {
        $sender = new class implements WhatsAppSender
        {
            public int $calls = 0;

            public function configured(): bool
            {
                return true;
            }

            public function sendTemplate(string $to, string $template, string $language, array $bodyParameters): string
            {
                $this->calls++;

                return 'wamid.test';
            }
        };

        $this->app->instance(WhatsAppSender::class, $sender);
        config()->set('integrations.whatsapp.appointment_template', 'appointment_reminder');
        config()->set('integrations.whatsapp.reminder_minutes', 1440);

        $appointment = $this->appointmentForTomorrow();
        $patient = $appointment->patientProfile;

        PatientConsent::query()->create([
            'patient_profile_id' => $patient->id,
            'clinic_id' => $patient->clinic_id,
            'granted_by_user_id' => $patient->user_id,
            'scope' => PatientConsentScope::WhatsAppNotifications,
            'policy_version' => (string) config('clinical.consent_policy_version'),
            'status' => PatientConsentStatus::Granted,
            'source' => 'test',
            'effective_from' => now()->subMinute(),
        ]);

        $this->artisan('appointments:send-whatsapp-reminders')->assertSuccessful();

        $this->assertSame(1, $sender->calls);
        $this->assertDatabaseHas('appointment_notification_deliveries', [
            'appointment_id' => $appointment->id,
            'channel' => 'whatsapp',
            'kind' => 'appointment_reminder',
            'status' => 'sent',
            'provider_reference' => 'wamid.test',
        ]);
    }

    private function appointmentForTomorrow(): Appointment
    {
        $clinic = Clinic::factory()->create();
        $patientUser = User::factory()->create([
            'name' => 'Paciente Recordatorio',
            'phone_number' => '+525512345678',
        ]);
        $patient = $patientUser->patientProfile()->create([
            'clinic_id' => $clinic->id,
        ]);
        $doctor = User::factory()->create(['name' => 'Dra. Recordatorio']);

        return Appointment::factory()->create([
            'clinic_id' => $clinic->id,
            'patient_profile_id' => $patient->id,
            'doctor_user_id' => $doctor->id,
            'scheduled_at' => now()->addDay(),
            'status' => 'confirmed',
        ]);
    }
}
