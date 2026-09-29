<?php

use Cron\CronExpression;
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

    if (preg_match('/^daily@([01]\\d|2[0-3]):([0-5]\\d)$/', $backupSchedule, $matches) === 1) {
        Schedule::command('backups:create')
            ->dailyAt($matches[1].':'.$matches[2])
            ->withoutOverlapping()
            ->onOneServer();
    } elseif (CronExpression::isValidExpression($backupSchedule)) {
        Schedule::command('backups:create')
            ->cron($backupSchedule)
            ->withoutOverlapping()
            ->onOneServer();
    } else {
        Log::error('BACKUP_SCHEDULE inválido; el respaldo automático no fue programado.', [
            'schedule' => $backupSchedule,
        ]);
    }
}
