<?php

namespace App\Console\Commands;

use App\Services\Backups\DatabaseBackupManager;
use Illuminate\Console\Command;
use Throwable;

class CreateDatabaseBackup extends Command
{
    protected $signature = 'backups:create';

    protected $description = 'Crea un respaldo PostgreSQL cifrado por el almacenamiento configurado y emite un manifiesto de integridad.';

    public function handle(DatabaseBackupManager $manager): int
    {
        try {
            $manifest = $manager->create();
            $this->info('Respaldo creado: '.$manifest['id']);
            $this->line('SHA-256: '.$manifest['sha256']);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
