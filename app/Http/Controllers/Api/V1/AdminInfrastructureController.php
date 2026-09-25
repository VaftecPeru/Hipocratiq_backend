<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\BillingGateway;
use App\Contracts\EpidemiologyAnalyzer;
use App\Contracts\VideoMeetingProvider;
use App\Contracts\WhatsAppSender;
use App\Http\Controllers\Controller;
use App\Services\Backups\DatabaseBackupManager;
use App\Services\Mail\MailInfrastructureService;
use Illuminate\Http\JsonResponse;

class AdminInfrastructureController extends Controller
{
    public function __construct(
        private DatabaseBackupManager $backupManager,
        private EpidemiologyAnalyzer $epidemiologyAnalyzer,
        private VideoMeetingProvider $videoProvider,
        private WhatsAppSender $whatsAppSender,
        private MailInfrastructureService $mailInfrastructure,
    ) {}

    public function show(): JsonResponse
    {
        $backupEnabled = $this->backupManager->configured();
        $backupProvider = config('admin_operations.backups.provider');
        $backupDisk = config('admin_operations.backups.disk');
        $backupHistory = $this->backupManager->history();

        return response()->json([
            'success' => true,
            'data' => [
                'backups' => [
                    'status' => $backupEnabled ? 'configured' : 'not_configured',
                    'provider' => $backupProvider,
                    'storage_disk' => $backupDisk,
                    'retention_days' => (int) config('admin_operations.backups.retention_days', 30),
                    'schedule' => config('admin_operations.backups.schedule'),
                    'history' => $backupHistory,
                    'capabilities' => [
                        'create' => $backupEnabled,
                        'download' => $backupEnabled,
                        'restore' => $backupEnabled,
                        'verify' => $backupEnabled,
                    ],
                    'message' => $backupEnabled
                        ? 'Respaldos PostgreSQL con manifiesto SHA-256 y restauración explícita disponibles.'
                        : 'No hay un servicio de respaldos configurado en el backend.',
                ],
                'integrations' => $this->integrations(),
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function integrations(): array
    {
        $mailStatus = $this->mailInfrastructure->status();
        $mailDriver = (string) ($mailStatus['driver'] ?? 'log');
        $smsDriver = (string) config('sms.driver', 'array');
        $billingAdapter = class_basename(app(BillingGateway::class));
        $filesystemDisk = (string) config('filesystems.default', 'local');
        $aiReady = $this->epidemiologyAnalyzer->configured();
        $videoReady = $this->videoProvider->configured();
        $whatsAppReady = $this->whatsAppSender->configured();
        $cacheStore = (string) config('cache.default', 'database');
        $mailReady = (bool) ($mailStatus['configured'] ?? false);
        $smsReady = $smsDriver === 'twilio'
            && filled(config('sms.twilio.account_sid'))
            && filled(config('sms.twilio.auth_token'))
            && filled(config('sms.twilio.messaging_service_sid'));
        $billingReady = str_contains(strtolower($billingAdapter), 'httpbillinggateway')
            && filled(config('billing.http.endpoint'))
            && filled(config('billing.http.token'));
        $storageReady = $filesystemDisk === 's3'
            && filled(config('filesystems.disks.s3.key'))
            && filled(config('filesystems.disks.s3.secret'))
            && filled(config('filesystems.disks.s3.bucket'));

        return [
            $this->integration(
                'email',
                'Correo transaccional',
                $mailDriver.':'.(string) ($mailStatus['auth_mode'] ?? 'unknown'),
                $mailReady,
                $mailReady ? 'configured' : 'not_configured',
                'OTP y correo transaccional. Host '.((string) ($mailStatus['host'] ?? 'no configurado')).':'.((string) ($mailStatus['port'] ?? '0')).'.',
            ),
            $this->integration(
                'sms',
                'SMS / OTP',
                $smsDriver,
                $smsReady,
                $smsReady ? 'configured' : ($smsDriver === 'array' ? 'sandbox' : 'not_configured'),
                'Entrega de códigos de verificación.',
            ),
            $this->integration(
                'billing',
                'Facturación',
                $billingAdapter,
                $billingReady,
                $billingReady ? 'configured' : (str_contains(strtolower($billingAdapter), 'simulated') ? 'sandbox' : 'not_configured'),
                'Compras y suscripciones de clínicas.',
            ),
            $this->integration(
                'storage',
                'Almacenamiento de archivos',
                $filesystemDisk,
                $storageReady,
                $storageReady ? 'configured' : ($filesystemDisk === 'local' ? 'local' : 'not_configured'),
                'Documentos privados de la plataforma.',
            ),
            $this->integration(
                'cache',
                'Cache y sesiones',
                $cacheStore,
                $cacheStore === 'redis',
                $cacheStore === 'redis' ? 'configured' : 'local',
                'Cache, sesiones, límites y bloqueos distribuidos.',
            ),
            $this->integration(
                'ai_epidemiology',
                'IA epidemiológica',
                $this->epidemiologyAnalyzer->provider(),
                $aiReady,
                $aiReady ? 'configured' : 'not_configured',
                'Análisis agregado sin datos personales individuales.',
            ),
            $this->integration(
                'telemedicine',
                'Telemedicina',
                (string) config('integrations.telemedicine.provider'),
                $videoReady,
                $videoReady ? 'configured' : 'not_configured',
                'Salas de videollamada para teleconsultas.',
            ),
            $this->integration(
                'whatsapp',
                'WhatsApp',
                (string) config('integrations.whatsapp.driver'),
                $whatsAppReady,
                $whatsAppReady ? 'configured' : 'not_configured',
                'Recordatorios de citas con consentimiento.',
            ),
            $this->integration('his_ehr', 'HIS / EHR', null, false, 'not_configured', 'Intercambio de historias clínicas.'),
            $this->integration('laboratory', 'Laboratorio / HL7', null, false, 'not_configured', 'Resultados y órdenes de laboratorio.'),
            $this->integration('pacs', 'PACS / Imágenes', null, false, 'not_configured', 'Imágenes diagnósticas y estudios.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function integration(
        string $key,
        string $name,
        ?string $adapter,
        bool $productionReady,
        string $status,
        string $purpose,
    ): array {
        return compact('key', 'name', 'adapter', 'productionReady', 'status', 'purpose');
    }
}
