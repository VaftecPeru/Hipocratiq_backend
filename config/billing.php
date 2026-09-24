<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Billing driver
    |--------------------------------------------------------------------------
    |
    | "unavailable" is the safe default. The simulated driver is intended only
    | for local development and automated tests; production must provide and
    | select a real gateway implementation before paid plans can be activated.
    |
    */
    'driver' => env('BILLING_DRIVER', 'unavailable'),

    'http' => [
        'endpoint' => env('BILLING_HTTP_ENDPOINT'),
        'token' => env('BILLING_HTTP_TOKEN'),
        'timeout_seconds' => (int) env('BILLING_HTTP_TIMEOUT_SECONDS', 20),
    ],
];
