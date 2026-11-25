<?php

namespace Tests\Feature\Backups;

use App\Services\Backups\DatabaseBackupManager;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class DatabaseManifestPathGuardTest extends TestCase
{
    public function test_restore_rejects_manifest_redirected_to_another_dump_without_running_process(): void
    {
        $this->configureSyntheticRestore();
        $this->writeManifest([
            'id' => 'safe_backup',
            'provider' => 'postgresql',
            'disk' => 'local',
            'database' => 'synthetic_source',
            'dump_path' => 'hipocratiq_test/database/other_backup.dump',
            'manifest_path' => 'hipocratiq_test/manifests/safe_backup.json',
        ]);
        Process::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Identidad o rutas del manifiesto de respaldo no válidas.');

        try {
            app(DatabaseBackupManager::class)->restore('safe_backup');
        } finally {
            Process::assertNothingRan();
        }
    }

    public function test_restore_rejects_manifest_with_mismatched_identity_without_running_process(): void
    {
        $this->configureSyntheticRestore();
        $this->writeManifest([
            'id' => 'other_backup',
            'provider' => 'postgresql',
            'disk' => 'local',
            'database' => 'synthetic_source',
            'dump_path' => 'hipocratiq_test/database/safe_backup.dump',
            'manifest_path' => 'hipocratiq_test/manifests/safe_backup.json',
        ]);
        Process::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Identidad o rutas del manifiesto de respaldo no válidas.');

        try {
            app(DatabaseBackupManager::class)->restore('safe_backup');
        } finally {
            Process::assertNothingRan();
        }
    }

    private function configureSyntheticRestore(): void
    {
        config()->set('app.env', 'testing');
        config()->set('admin_operations.backups.restore_enabled', true);
        config()->set('database.default', 'pgsql');
        config()->set('database.connections.pgsql.driver', 'pgsql');
        config()->set('database.connections.pgsql.database', 'hipocratiq_restore_synthetic');
        config()->set('database.connections.pgsql.host', '127.0.0.1');
        config()->set('admin_operations.backups.restore_target_database', 'hipocratiq_restore_synthetic');
        config()->set('admin_operations.backups.restore_target_host', '127.0.0.1');
        config()->set('admin_operations.backups.disk', 'local');
        config()->set('admin_operations.backups.prefix', 'hipocratiq_test');
        Storage::fake('local');
    }

    /** @param array<string, string> $manifest */
    private function writeManifest(array $manifest): void
    {
        Storage::disk('local')->put(
            'hipocratiq_test/manifests/safe_backup.json',
            json_encode($manifest, JSON_THROW_ON_ERROR)
        );
    }
}
