<?php

namespace Tests\Feature;

use App\Billing\SubscriptionPurchase;
use App\Enums\TeleconsultationStatus;
use App\Models\Teleconsultation;
use App\Services\Backups\DatabaseBackupManager;
use App\Services\Billing\HttpBillingGateway;
use App\Services\Epidemiology\OpenAiEpidemiologyAnalyzer;
use App\Services\Telemedicine\JitsiVideoMeetingProvider;
use App\Services\WhatsApp\MetaWhatsAppSender;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Phase3IntegrationAdaptersTest extends TestCase
{
    public function test_openai_epidemiology_adapter_uses_aggregate_snapshot_and_parses_json(): void
    {
        config()->set('integrations.ai.api_key', 'test-key');
        config()->set('integrations.ai.model', 'test-model');
        config()->set('integrations.ai.base_url', 'https://api.openai.test/v1');

        Http::fake([
            'https://api.openai.test/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'trend' => 'rising',
                    'forecasts' => [['title' => 'Mayor demanda', 'detail' => 'Revisar capacidad', 'horizon_days' => 14]],
                    'alerts' => [['title' => 'Aumento de eventos', 'detail' => 'Validar tendencia', 'severity' => 'warning']],
                    'recommendations' => ['Revisar disponibilidad clínica.'],
                    'summary' => 'Se observa un incremento agregado de actividad.',
                ], JSON_THROW_ON_ERROR),
            ]),
        ]);

        $result = (new OpenAiEpidemiologyAnalyzer)->analyze([
            'period' => ['days' => 90],
            'metrics' => ['appointments' => 12, 'consultations' => 8],
        ]);

        $this->assertSame('rising', $result['trend']);
        $this->assertCount(1, $result['alerts']);
        $this->assertSame('Se observa un incremento agregado de actividad.', $result['summary']);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.openai.test/v1/responses'
                && $payload['model'] === 'test-model'
                && str_contains((string) $payload['input'], '"appointments":12')
                && ! str_contains((string) $payload['input'], 'patient_name');
        });
    }

    public function test_jitsi_provider_generates_deterministic_join_url_without_exposing_secret(): void
    {
        config()->set('integrations.telemedicine.base_url', 'https://meet.example.test');
        config()->set('integrations.telemedicine.room_prefix', 'hipocratiq');
        config()->set('integrations.telemedicine.room_secret', 'very-secret-room-key');

        $session = new Teleconsultation;
        $session->id = 42;
        $session->status = TeleconsultationStatus::Scheduled;

        $connection = (new JitsiVideoMeetingProvider)->connection($session);

        $this->assertTrue($connection['joinable']);
        $this->assertSame('configured', $connection['status']);
        $this->assertStringStartsWith('https://meet.example.test/hipocratiq-42-', $connection['join_url']);
        $this->assertStringNotContainsString('very-secret-room-key', $connection['join_url']);
    }

    public function test_meta_whatsapp_sender_uses_template_contract(): void
    {
        config()->set('integrations.whatsapp.api_version', 'v-test');
        config()->set('integrations.whatsapp.phone_number_id', '123456');
        config()->set('integrations.whatsapp.access_token', 'test-token');
        config()->set('integrations.whatsapp.base_url', 'https://graph.example.test');

        Http::fake([
            'https://graph.example.test/v-test/123456/messages' => Http::response([
                'messages' => [['id' => 'wamid.test']],
            ]),
        ]);

        $reference = (new MetaWhatsAppSender)->sendTemplate(
            '+525500000000',
            'appointment_reminder',
            'es_MX',
            ['Paciente', '24/09/2026 10:00', 'Dra. Rivera', 'Clínica Central'],
        );

        $this->assertSame('wamid.test', $reference);
        Http::assertSent(fn ($request): bool => $request['messaging_product'] === 'whatsapp'
            && $request['to'] === '525500000000'
            && $request['template']['name'] === 'appointment_reminder');
    }

    public function test_http_billing_gateway_only_accepts_externally_confirmed_payment(): void
    {
        config()->set('billing.http.endpoint', 'https://billing.example.test/capture');
        config()->set('billing.http.token', 'billing-token');

        Http::fake([
            'https://billing.example.test/capture' => Http::response([
                'status' => 'succeeded',
                'provider' => 'external_gateway',
                'subscription_reference' => 'sub_123',
                'payment_reference' => 'pay_123',
                'paid_at' => '2026-09-24T13:00:00Z',
            ]),
        ]);

        $receipt = (new HttpBillingGateway)->purchase(new SubscriptionPurchase(
            idempotencyKey: 'clinic:test',
            amountCents: 4900,
            currency: 'USD',
            subjectType: 'clinic',
            planKey: 'basic',
            billingCycle: 'monthly',
        ));

        $this->assertSame('external_gateway', $receipt->provider);
        $this->assertSame('pay_123', $receipt->paymentReference);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Idempotency-Key', 'clinic:test'));
    }

    public function test_postgresql_backup_creates_and_verifies_manifest(): void
    {
        Storage::fake('backup-test');
        config()->set('admin_operations.backups.enabled', true);
        config()->set('admin_operations.backups.provider', 'postgresql');
        config()->set('admin_operations.backups.disk', 'backup-test');
        config()->set('admin_operations.backups.prefix', 'hipocratiq-test');
        config()->set('database.default', 'pgsql');
        config()->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => '5432',
            'database' => 'hipocratiq_test',
            'username' => 'tester',
            'password' => 'secret',
        ]);

        Process::fake([
            'pg_dump *' => Process::result(output: 'binary-backup-content'),
        ]);

        $manager = new DatabaseBackupManager;
        $manifest = $manager->create();
        $verified = $manager->verify((string) $manifest['id']);

        $this->assertSame(hash('sha256', 'binary-backup-content'), $manifest['sha256']);
        $this->assertNotNull($verified['verified_at']);
        Storage::disk('backup-test')->assertExists((string) $manifest['dump_path']);
        Storage::disk('backup-test')->assertExists((string) $manifest['manifest_path']);
    }
}
