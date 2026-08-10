<?php

return [
    'backups' => [
        'enabled' => (bool) env('BACKUP_SERVICE_ENABLED', false),
        'provider' => env('BACKUP_SERVICE_PROVIDER'),
        'disk' => env('BACKUP_STORAGE_DISK'),
        'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),
        'schedule' => env('BACKUP_SCHEDULE'),
    ],
    'ai' => [
        'enabled' => (bool) env('EPIDEMIOLOGICAL_AI_ENABLED', false),
        'provider' => env('EPIDEMIOLOGICAL_AI_PROVIDER'),
    ],
];
