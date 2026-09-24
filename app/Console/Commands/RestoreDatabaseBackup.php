<?php

namespace App\Console\Commands;

use App\Services\Backups\DatabaseBackupManager;
use Illuminate\Console\Command;
use Throwable;

class RestoreDatabaseBackup extends Command
{
    protected $signature = 'backups:restore {id} {--confirm=}';

    protected $description = 'Restaura PostgreSQL desde un respaldo verificado. Requiere confirmación explícita.';

    public function handle(DatabaseBackupManager $manager): int
    {
        $id = (string) $this->argument('id');

        if ($this->option('confirm') !== $id) {
            $this->error('Restauración cancelada. Usa --confirm='.$id.' para confirmar el respaldo exacto.');

            return self::FAILURE;
        }

        try {
            $manager->restore($id);
            $this->info('Restauración completada: '.$id);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
