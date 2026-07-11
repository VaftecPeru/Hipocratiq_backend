<?php

return [
    'otp' => [
        'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 5),
        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
        'resend_seconds' => (int) env('OTP_RESEND_SECONDS', 60),
        'hash_key' => env('OTP_HASH_KEY') ?: env('APP_KEY'),
        'fixed_code' => env('OTP_FIXED_CODE'),
    ],
    'session' => [
        'absolute_minutes' => (int) env('AUTH_SESSION_ABSOLUTE_MINUTES', 720),
    ],
];
