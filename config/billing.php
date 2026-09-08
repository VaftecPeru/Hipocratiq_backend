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
];
