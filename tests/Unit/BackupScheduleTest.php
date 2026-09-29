<?php

namespace Tests\Unit;

use App\Support\Backups\BackupSchedule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BackupScheduleTest extends TestCase
{
    public function test_parses_daily_schedule(): void
    {
        self::assertSame(
            ['mode' => 'daily', 'value' => '02:15'],
            BackupSchedule::parse('daily@02:15'),
        );
    }

    public function test_parses_standard_cron_expression(): void
    {
        self::assertSame(
            ['mode' => 'cron', 'value' => '0 3 * * *'],
            BackupSchedule::parse('0 3 * * *'),
        );
    }

    #[DataProvider('invalidSchedules')]
    public function test_rejects_invalid_schedule(string $schedule): void
    {
        self::assertNull(BackupSchedule::parse($schedule));
    }

    /** @return array<string, array{string}> */
    public static function invalidSchedules(): array
    {
        return [
            'empty' => [''],
            'bad hour' => ['daily@25:00'],
            'bad minute' => ['daily@02:75'],
            'garbage' => ['tomorrow at two'],
        ];
    }
}
