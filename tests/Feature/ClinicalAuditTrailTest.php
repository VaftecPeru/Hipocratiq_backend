<?php

namespace Tests\Feature;

use App\Actions\Clinical\RecordClinicalAuditEvent;
use App\Models\Clinic;
use App\Models\Consultation;
use App\Models\PatientProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class ClinicalAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_clinical_audit_snapshots_are_encrypted_and_recoverable(): void
    {
        $actor = User::factory()->create();
        $clinic = Clinic::factory()->create();
        $patient = PatientProfile::factory()->create(['clinic_id' => $clinic->id]);
        $consultation = Consultation::factory()->create([
            'clinic_id' => $clinic->id,
            'doctor_user_id' => $actor->id,
            'patient_profile_id' => $patient->id,
        ]);

        $request = Request::create('/api/v1/auth/doctor/consultations/'.$consultation->id, 'PATCH');
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        $request->headers->set('User-Agent', 'HIPOCRATIQ-UAT');
        $request->headers->set('X-Request-ID', 'clinical-audit-test');
        $request->setUserResolver(fn () => $actor);

        $event = app(RecordClinicalAuditEvent::class)->handle(
            actor: $actor,
            request: $request,
            consultation: $consultation,
            action: 'consultation.updated',
            before: ['status' => 'in_progress', 'chief_complaint' => 'Antes'],
            after: ['status' => 'completed', 'chief_complaint' => 'Después'],
        );

        $this->assertSame('in_progress', $event->before_payload['status']);
        $this->assertSame('completed', $event->after_payload['status']);
        $this->assertSame($patient->id, $event->patient_profile_id);
        $this->assertSame('clinical-audit-test', $event->request_id);

        $raw = DB::table('clinical_audit_events')->where('id', $event->id)->firstOrFail();

        $this->assertNotSame(json_encode($event->before_payload), $raw->before_payload);
        $this->assertNotSame(json_encode($event->after_payload), $raw->after_payload);
        $this->assertStringNotContainsString('chief_complaint', (string) $raw->before_payload);
        $this->assertStringNotContainsString('chief_complaint', (string) $raw->after_payload);
    }

    public function test_clinical_audit_event_cannot_be_updated(): void
    {
        $event = $this->createAuditEvent();

        $this->expectException(LogicException::class);
        $event->update(['action' => 'tampered']);
    }

    public function test_clinical_audit_event_cannot_be_deleted(): void
    {
        $event = $this->createAuditEvent();

        $this->expectException(LogicException::class);
        $event->delete();
    }

    private function createAuditEvent()
    {
        $actor = User::factory()->create();
        $clinic = Clinic::factory()->create();
        $patient = PatientProfile::factory()->create(['clinic_id' => $clinic->id]);
        $consultation = Consultation::factory()->create([
            'clinic_id' => $clinic->id,
            'doctor_user_id' => $actor->id,
            'patient_profile_id' => $patient->id,
        ]);

        $request = Request::create('/api/v1/auth/doctor/consultations/'.$consultation->id, 'PATCH');
        $request->setUserResolver(fn () => $actor);

        return app(RecordClinicalAuditEvent::class)->handle(
            actor: $actor,
            request: $request,
            consultation: $consultation,
            action: 'consultation.updated',
            before: ['status' => 'draft'],
            after: ['status' => 'in_progress'],
        );
    }
}
