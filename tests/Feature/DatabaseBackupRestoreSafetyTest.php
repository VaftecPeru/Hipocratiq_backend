<?php

namespace Tests\Feature;

use App\Services\Backups\DatabaseBackupManager;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class DatabaseBackupRestoreSafetyTest extends TestCase
{
    private const ID = 'synthetic_restore_001';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backup-drill');
        config([
            'app.env' => 'testing',
            'admin_operations.backups.disk' => 'backup-drill',
            'admin_operations.backups.prefix' => 'hipocratiq-drill',
            'admin_operations.backups.restore_enabled' => false,
            'admin_operations.backups.restore_target_database' => '',
            'admin_operations.backups.restore_target_host' => '',
            'database.default' => 'pgsql',
            'database.connections.pgsql' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'port' => '5432',
                'database' => 'hipocratiq_restore_qa',
                'username' => 'synthetic_qa',
                'password' => 'synthetic_only',
            ],
        ]);

        $dump = 'synthetic-backup-bytes';
        Storage::disk('backup-drill')->put('hipocratiq-drill/database/'.self::ID.'.dump', $dump);
        Storage::disk('backup-drill')->put(
            'hipocratiq-drill/manifests/'.self::ID.'.json',
            json_encode([
                'id' => self::ID,
                'provider' => 'postgresql',
                'database' => 'hipocratiq_source',
                'dump_path' => 'hipocratiq-drill/database/'.self::ID.'.dump',
                'manifest_path' => 'hipocratiq-drill/manifests/'.self::ID.'.json',
                'sha256' => hash('sha256', $dump),
                'size_bytes' => strlen($dump),
                'verified_at' => null,
            ], JSON_THROW_ON_ERROR),
        );
        Process::fake();
    }

    private function enableApprovedDrill(): void
    {
        config([
            'admin_operations.backups.restore_enabled' => true,
            'admin_operations.backups.restore_target_database' => 'hipocratiq_restore_qa',
            'admin_operations.backups.restore_target_host' => '127.0.0.1',
        ]);
    }

    public function test_restore_is_disabled_by_default_before_manifest_mutation(): void
    {
        $this->assertRestoreBlocked('Restauración deshabilitada');
    }

    public function test_restore_never_runs_in_production_even_when_explicitly_enabled(): void
    {
        $this->enableApprovedDrill();
        config(['app.env' => 'production']);

        $this->assertRestoreBlocked('entorno de producción');
    }

    public function test_restore_requires_exact_host_and_database_allowlist(): void
    {
        $this->enableApprovedDrill();
        config(['admin_operations.backups.restore_target_host' => 'other-host']);

        $this->assertRestoreBlocked('Destino de restauración no aislado');
    }

    public function test_restore_refuses_original_database_even_if_approved(): void
    {
        $this->enableApprovedDrill();
        config([
            'database.connections.pgsql.database' => 'hipocratiq_source',
            'admin_operations.backups.restore_target_database' => 'hipocratiq_source',
        ]);

        $this->assertRestoreBlocked('Destino de restauración no aislado');
    }

    public function test_restore_rejects_non_drill_database_name(): void
    {
        $this->enableApprovedDrill();
        config([
            'database.connections.pgsql.database' => 'hipocratiq_patient_data',
            'admin_operations.backups.restore_target_database' => 'hipocratiq_patient_data',
        ]);

        $this->assertRestoreBlocked('Destino de restauración no aislado');
    }

    public function test_approved_nonproduction_isolated_target_can_be_rehearsed_with_fake_process(): void
    {
        $this->enableApprovedDrill();

        (new DatabaseBackupManager)->restore(self::ID);

        Process::assertRan(fn ($process): bool => is_string($process->command)
            && str_starts_with($process->command, 'pg_restore '));
        $manifest = json_decode(
            Storage::disk('backup-drill')->get('hipocratiq-drill/manifests/'.self::ID.'.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertNotNull($manifest['verified_at']);
    }

    private function assertRestoreBlocked(string $message): void
    {
        $before = Storage::disk('backup-drill')->get('hipocratiq-drill/manifests/'.self::ID.'.json');

        try {
            (new DatabaseBackupManager)->restore(self::ID);
            $this->fail('An unsafe restore must not be accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }

        $this->assertSame(
            $before,
            Storage::disk('backup-drill')->get('hipocratiq-drill/manifests/'.self::ID.'.json'),
        );
        Process::assertNothingRan();
    }
}
