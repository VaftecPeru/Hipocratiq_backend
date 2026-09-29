<?php

namespace App\Support\Backups;

use Cron\CronExpression;

final class BackupSchedule
{
    /**
     * @return array{mode: 'daily', value: string}|array{mode: 'cron', value: string}|null
     */
    public static function parse(string $schedule): ?array
    {
        $schedule = trim($schedule);

        if (preg_match('/^daily@([01]\\d|2[0-3]):([0-5]\\d)$/', $schedule, $matches) === 1) {
            return [
                'mode' => 'daily',
                'value' => $matches[1].':'.$matches[2],
            ];
        }

        if ($schedule !== '' && CronExpression::isValidExpression($schedule)) {
            return [
                'mode' => 'cron',
                'value' => $schedule,
            ];
        }

        return null;
    }
}
