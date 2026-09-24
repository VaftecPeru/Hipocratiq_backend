<?php

namespace App\Console\Commands;

use App\Services\Backups\DatabaseBackupManager;
use Illuminate\Console\Command;
use Throwable;

class VerifyDatabaseBackup extends Command
{
    protected $signature = 'backups:verify {id}';

    protected $description = 'Verifica tamaño y SHA-256 de un respaldo HIPOCRATIQ.';

    public function handle(DatabaseBackupManager $manager): int
    {
        try {
            $manifest = $manager->verify((string) $this->argument('id'));
            $this->info('Respaldo verificado: '.$manifest['id']);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
