<?php

return [
    // Cuando es true, se permiten proveedores de demo (SMS array, OTP fijo y
    // billing simulado) aun con APP_ENV=production. Uso exclusivo para demos
    // controladas; mantener en false en un entorno productivo real.
    'demo_mode' => (bool) env('DEMO_MODE', false),
    'otp' => [
        'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 5),
        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
        'max_resends' => (int) env('OTP_MAX_RESENDS', 5),
        'resend_seconds' => (int) env('OTP_RESEND_SECONDS', 60),
        'hash_key' => env('OTP_HASH_KEY') ?: env('APP_KEY'),
        'fixed_code' => env('OTP_FIXED_CODE'),
        'phone_provider' => env('OTP_PHONE_PROVIDER', 'local'),
        'aws_notify' => [
            'region' => env('AWS_NOTIFY_REGION', env('AWS_DEFAULT_REGION', 'us-east-2')),
            'origination_identity' => env('AWS_NOTIFY_ORIGINATION_IDENTITY'),
            'notify_code_configuration' => env('AWS_NOTIFY_CODE_CONFIGURATION'),
            'configuration_set_name' => env('AWS_NOTIFY_CONFIGURATION_SET'),
        ],
    ],
    'session' => [
        'absolute_minutes' => (int) env('AUTH_SESSION_ABSOLUTE_MINUTES', 720),
    ],
    'mobile_token' => [
        'ttl_minutes' => (int) env('AUTH_MOBILE_TOKEN_MINUTES', 43200),
    ],
    'invitation' => [
        'ttl_minutes' => (int) env('AUTH_INVITATION_TTL_MINUTES', 1440),
    ],
];
