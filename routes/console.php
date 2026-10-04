<?php

use App\Support\Backups\BackupSchedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('appointments:send-whatsapp-reminders')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

if (config('admin_operations.backups.enabled')) {
    $configuredSchedule = trim((string) config('admin_operations.backups.schedule', 'daily@02:00'));
    $backupSchedule = BackupSchedule::parse($configuredSchedule);

    if ($backupSchedule === null) {
        Log::error('BACKUP_SCHEDULE inválido; el respaldo automático no fue programado.', [
            'schedule' => $configuredSchedule,
        ]);
    } else {
        $event = Schedule::command('backups:create')
            ->withoutOverlapping()
            ->onOneServer();

        if ($backupSchedule['mode'] === 'daily') {
            $event->dailyAt($backupSchedule['value']);
        } else {
            $event->cron($backupSchedule['value']);
        }
    }
}
