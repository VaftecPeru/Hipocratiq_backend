<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\BillingGateway;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AdminInfrastructureController extends Controller
{
    public function show(): JsonResponse
    {
        $backupEnabled = (bool) config('admin_operations.backups.enabled');
        $backupProvider = config('admin_operations.backups.provider');
        $backupDisk = config('admin_operations.backups.disk');

        return response()->json([
            'success' => true,
            'data' => [
                'backups' => [
                    'status' => $backupEnabled && $backupProvider && $backupDisk ? 'configured' : 'not_configured',
                    'provider' => $backupProvider,
                    'storage_disk' => $backupDisk,
                    'retention_days' => (int) config('admin_operations.backups.retention_days', 30),
                    'schedule' => config('admin_operations.backups.schedule'),
                    'history' => [],
                    'capabilities' => [
                        'create' => false,
                        'download' => false,
                        'restore' => false,
                        'verify' => false,
                    ],
                    'message' => $backupEnabled
                        ? 'El proveedor está declarado, pero aún falta conectar manifiestos y operaciones de restauración.'
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
        $mailDriver = (string) config('mail.default', 'log');
        $smsDriver = (string) config('sms.driver', 'array');
        $billingAdapter = class_basename(app(BillingGateway::class));
        $filesystemDisk = (string) config('filesystems.default', 'local');
        $cacheStore = (string) config('cache.default', 'database');
        $mailReady = ! in_array($mailDriver, ['log', 'array'], true);
        $smsReady = $smsDriver === 'twilio'
            && filled(config('sms.twilio.account_sid'))
            && filled(config('sms.twilio.auth_token'))
            && filled(config('sms.twilio.messaging_service_sid'));
        $billingReady = ! str_contains(strtolower($billingAdapter), 'simulated')
            && ! str_contains(strtolower($billingAdapter), 'unavailable');
        $storageReady = $filesystemDisk === 's3'
            && filled(config('filesystems.disks.s3.key'))
            && filled(config('filesystems.disks.s3.secret'))
            && filled(config('filesystems.disks.s3.bucket'));

        return [
            $this->integration(
                'email',
                'Correo transaccional',
                $mailDriver,
                $mailReady,
                $mailReady ? 'configured' : 'sandbox',
                'Notificaciones de cuenta y recuperación.',
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
