<?php

namespace Tests\Feature\Backups;

use App\Services\Backups\DatabaseBackupManager;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

final class DatabaseRestoreFailClosedTest extends TestCase
{
    public function test_restore_is_refused_in_production_before_any_process_is_started(): void
    {
        config()->set('app.env', 'production');
        config()->set('admin_operations.backups.restore_enabled', true);
        Process::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Restauración denegada en el entorno de producción.');

        try {
            app(DatabaseBackupManager::class)->restore('synthetic_backup');
        } finally {
            Process::assertNothingRan();
        }
    }

    public function test_restore_requires_explicit_opt_in_before_reading_manifest(): void
    {
        config()->set('app.env', 'testing');
        config()->set('admin_operations.backups.restore_enabled', false);
        Process::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Restauración deshabilitada por defecto.');

        try {
            app(DatabaseBackupManager::class)->restore('synthetic_backup');
        } finally {
            Process::assertNothingRan();
        }
    }

    public function test_restore_rejects_non_postgresql_connection_before_reading_manifest(): void
    {
        config()->set('app.env', 'testing');
        config()->set('admin_operations.backups.restore_enabled', true);
        config()->set('database.default', 'sqlite');
        Process::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('La restauración requiere una conexión PostgreSQL.');

        try {
            app(DatabaseBackupManager::class)->restore('synthetic_backup');
        } finally {
            Process::assertNothingRan();
        }
    }

    public function test_restore_rejects_invalid_backup_identifier_without_running_external_process(): void
    {
        config()->set('app.env', 'testing');
        config()->set('admin_operations.backups.restore_enabled', true);
        config()->set('database.default', 'pgsql');
        Process::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Identificador de respaldo inválido.');

        try {
            app(DatabaseBackupManager::class)->restore('../synthetic_backup');
        } finally {
            Process::assertNothingRan();
        }
    }
    public function test_restore_rejects_unapproved_target_before_any_external_process(): void
    {
        config()->set('app.env', 'testing');
        config()->set('admin_operations.backups.restore_enabled', true);
        config()->set('database.default', 'pgsql');
        config()->set('database.connections.pgsql.database', 'hipocratiq_restore_synthetic');
        config()->set('database.connections.pgsql.host', '127.0.0.1');
        config()->set('admin_operations.backups.restore_target_database', 'hipocratiq_restore_other');
        config()->set('admin_operations.backups.restore_target_host', '127.0.0.1');
        config()->set('admin_operations.backups.disk', 'local');
        config()->set('admin_operations.backups.prefix', 'hipocratiq_test');
        \\Illuminate\\Support\\Facades\\Storage::fake('local');
        \\Illuminate\\Support\\Facades\\Storage::disk('local')->put(
            'hipocratiq_test/manifests/synthetic_backup.json',
            json_encode(['id' => 'synthetic_backup', 'database' => 'hipocratiq_source'], JSON_THROW_ON_ERROR)
        );
        Process::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Destino de restauración no aislado o no autorizado.');

        try {
            app(DatabaseBackupManager::class)->restore('synthetic_backup');
        } finally {
            Process::assertNothingRan();
        }
    }

}
