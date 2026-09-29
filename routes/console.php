<?php

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
    $backupSchedule = trim((string) config('admin_operations.backups.schedule', 'daily@02:00'));
    $backupEvent = Schedule::command('backups:create')
        ->withoutOverlapping()
        ->onOneServer();

    if (preg_match('/^daily@([01]\\d|2[0-3]):([0-5]\\d)$/', $backupSchedule, $matches) === 1) {
        $backupEvent->dailyAt($matches[1].':'.$matches[2]);
    } elseif (preg_match('/^\\S+\\s+\\S+\\s+\\S+\\s+\\S+\\s+\\S+$/', $backupSchedule) === 1) {
        $backupEvent->cron($backupSchedule);
    } else {
        Log::warning('BACKUP_SCHEDULE inválido; se aplicará el horario seguro daily@02:00.', [
            'configured_schedule' => $backupSchedule,
        ]);
        $backupEvent->dailyAt('02:00');
    }
}
