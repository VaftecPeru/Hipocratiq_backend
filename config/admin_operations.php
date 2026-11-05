<?php

return [
    'backups' => [
        'enabled' => (bool) env('BACKUP_SERVICE_ENABLED', false),
        'provider' => env('BACKUP_SERVICE_PROVIDER'),
        'disk' => env('BACKUP_STORAGE_DISK'),
        'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),
        'schedule' => env('BACKUP_SCHEDULE', 'daily@02:00'),
        'prefix' => env('BACKUP_PREFIX', 'hipocratiq'),
        'timeout_seconds' => (int) env('BACKUP_TIMEOUT_SECONDS', 300),
        'restore_timeout_seconds' => (int) env('BACKUP_RESTORE_TIMEOUT_SECONDS', 600),
        'restore_enabled' => (bool) env('BACKUP_RESTORE_ENABLED', false),
        'restore_target_database' => env('BACKUP_RESTORE_TARGET_DATABASE', ''),
        'restore_target_host' => env('BACKUP_RESTORE_TARGET_HOST', ''),
    ],
    'ai' => [
        'enabled' => (bool) env('EPIDEMIOLOGICAL_AI_ENABLED', false),
        'provider' => env('EPIDEMIOLOGICAL_AI_PROVIDER', 'unavailable'),
    ],
];
