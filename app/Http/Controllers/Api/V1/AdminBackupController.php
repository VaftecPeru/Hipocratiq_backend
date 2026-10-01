<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Admin\RecordAdminAuditEvent;
use App\Http\Controllers\Controller;
use App\Services\Backups\DatabaseBackupManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AdminBackupController extends Controller
{
    public function __construct(
        private DatabaseBackupManager $backupManager,
        private RecordAdminAuditEvent $audit,
    ) {}

    public function store(Request $request): JsonResponse
    {
        if (! $this->backupManager->configured()) {
            return response()->json([
                'success' => false,
                'message' => 'El servicio de respaldos no está configurado.',
            ], 503);
        }

        try {
            $manifest = $this->backupManager->create();
        } catch (Throwable $exception) {
            $this->record($request, 'backup.create.failed', null, true, [
                'error' => mb_substr($exception->getMessage(), 0, 500),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo generar el respaldo.',
            ], 500);
        }

        $this->record($request, 'backup.created', (string) ($manifest['id'] ?? null), false, [
            'sha256' => $manifest['sha256'] ?? null,
            'size_bytes' => $manifest['size_bytes'] ?? null,
            'disk' => $manifest['disk'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $manifest,
        ], 201);
    }

    public function verify(Request $request, string $backup): JsonResponse
    {
        try {
            $manifest = $this->backupManager->verify($backup);
        } catch (Throwable $exception) {
            $this->record($request, 'backup.verify.failed', $backup, true, [
                'error' => mb_substr($exception->getMessage(), 0, 500),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo verificar la integridad del respaldo.',
            ], 422);
        }

        $this->record($request, 'backup.verified', $backup, false, [
            'sha256' => $manifest['sha256'] ?? null,
            'verified_at' => $manifest['verified_at'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $manifest,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function record(
        Request $request,
        string $action,
        ?string $backupId,
        bool $critical,
        array $metadata,
    ): void {
        $actor = $request->user();

        if ($actor === null) {
            return;
        }

        $this->audit->handle(
            actor: $actor,
            request: $request,
            module: 'backups',
            action: $action,
            resourceType: 'database_backup',
            resourceId: $backupId,
            resourceLabel: $backupId,
            metadata: $metadata,
            critical: $critical,
        );
    }
}
