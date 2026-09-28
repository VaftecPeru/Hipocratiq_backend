<?php

namespace Tests\Feature;

use App\Contracts\EpidemiologyAnalyzer;
use App\Enums\PatientConsentScope;
use App\Enums\PatientConsentStatus;
use App\Http\Controllers\Api\V1\ClinicEpidemiologyController;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\PatientConsent;
use App\Models\PatientProfile;
use App\Services\Epidemiology\OpenAiEpidemiologyAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ClinicEpidemiologyConsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_external_analysis_uses_only_consented_aggregate_cohort(): void
    {
        config([
            'clinical.consent_policy_version' => '2026-09',
            'clinical.epidemiology.minimum_external_cohort_size' => 1,
        ]);

        $clinic = Clinic::factory()->create();
        $consented = PatientProfile::factory()->create(['clinic_id' => $clinic->id]);
        $other = PatientProfile::factory()->create(['clinic_id' => $clinic->id]);

        foreach ([$consented, $other] as $patient) {
            Appointment::factory()->create([
                'clinic_id' => $clinic->id,
                'patient_profile_id' => $patient->id,
                'scheduled_at' => now()->subDay(),
            ]);
        }

        PatientConsent::query()->create([
            'patient_profile_id' => $consented->id,
            'clinic_id' => $clinic->id,
            'granted_by_user_id' => $consented->user_id,
            'scope' => PatientConsentScope::EpidemiologyAnonymized,
            'policy_version' => '2026-09',
            'status' => PatientConsentStatus::Granted,
            'source' => 'digital',
            'effective_from' => now()->subDay(),
        ]);

        $analyzer = new RecordingAggregateAnalyzer;
        $response = (new ClinicEpidemiologyController($analyzer))->show(
            $this->requestForClinic($clinic),
        );
        $payload = $response->getData(true);

        self::assertTrue($payload['data']['analysis_available']);
        self::assertSame(2, $payload['data']['metrics']['monitored_patients']);
        self::assertSame(1, $payload['data']['analysis_cohort']['monitored_patients']);
        self::assertSame('eligible', $payload['data']['analysis_cohort']['status']);
        self::assertCount(1, $analyzer->snapshots);
        self::assertSame(1, $analyzer->snapshots[0]['metrics']['monitored_patients']);
        self::assertSame(1, $analyzer->snapshots[0]['metrics']['appointments']);
    }

    public function test_external_analysis_is_suppressed_when_privacy_cohort_is_too_small(): void
    {
        config([
            'clinical.consent_policy_version' => '2026-09',
            'clinical.epidemiology.minimum_external_cohort_size' => 2,
        ]);

        $clinic = Clinic::factory()->create();
        $patient = PatientProfile::factory()->create(['clinic_id' => $clinic->id]);
        Appointment::factory()->create([
            'clinic_id' => $clinic->id,
            'patient_profile_id' => $patient->id,
            'scheduled_at' => now()->subDay(),
        ]);
        PatientConsent::query()->create([
            'patient_profile_id' => $patient->id,
            'clinic_id' => $clinic->id,
            'granted_by_user_id' => $patient->user_id,
            'scope' => PatientConsentScope::EpidemiologyAnonymized,
            'policy_version' => '2026-09',
            'status' => PatientConsentStatus::Granted,
            'source' => 'digital',
            'effective_from' => now()->subDay(),
        ]);

        $analyzer = new RecordingAggregateAnalyzer;
        $response = (new ClinicEpidemiologyController($analyzer))->show(
            $this->requestForClinic($clinic),
        );
        $payload = $response->getData(true);

        self::assertFalse($payload['data']['analysis_available']);
        self::assertSame('insufficient', $payload['data']['analysis_cohort']['status']);
        self::assertSame(2, $payload['data']['analysis_cohort']['minimum_required']);
        self::assertCount(0, $analyzer->snapshots);
    }

    public function test_openai_analyzer_respects_explicit_enable_flag(): void
    {
        config([
            'integrations.ai.enabled' => false,
            'integrations.ai.api_key' => 'test-key',
            'integrations.ai.model' => 'test-model',
            'integrations.ai.base_url' => 'https://example.invalid/v1',
        ]);

        self::assertFalse((new OpenAiEpidemiologyAnalyzer)->configured());
    }

    private function requestForClinic(Clinic $clinic): Request
    {
        $request = Request::create('/api/v1/auth/clinic/epidemiology', 'GET');
        $request->attributes->set('clinic', $clinic);

        return $request;
    }
}

final class RecordingAggregateAnalyzer implements EpidemiologyAnalyzer
{
    public array $snapshots = [];

    public function configured(): bool
    {
        return true;
    }

    public function provider(): string
    {
        return 'test';
    }

    public function analyze(array $snapshot): array
    {
        $this->snapshots[] = $snapshot;

        return [
            'trend' => 'stable',
            'forecasts' => [],
            'alerts' => [],
            'recommendations' => [],
            'summary' => 'Aggregate analysis fixture.',
        ];
    }
}
