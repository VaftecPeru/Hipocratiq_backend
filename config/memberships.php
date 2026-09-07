<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Moneda base de la plataforma
    |--------------------------------------------------------------------------
    |
    | Los precios de los planes se almacenan en centavos y en esta moneda.
    | La conversión a PEN es informativa para el dashboard de administración.
    |
    */

    'currency' => env('MEMBERSHIPS_CURRENCY', 'USD'),

    'supported_currencies' => ['USD', 'MXN', 'PEN'],

    /*
    |--------------------------------------------------------------------------
    | Tasa de conversión USD → PEN
    |--------------------------------------------------------------------------
    |
    | Se usa únicamente para presentar montos equivalentes en Soles en el dashboard
    | de membresías. No afecta los precios de facturación reales.
    |
    */

    'exchange_rate_usd_to_pen' => env('MEMBERSHIPS_EXCHANGE_RATE_USD_PEN'),

    'exchange_rates_usd' => [
        'USD' => 1.0,
        'MXN' => env('MEMBERSHIPS_EXCHANGE_RATE_USD_MXN'),
        'PEN' => env('MEMBERSHIPS_EXCHANGE_RATE_USD_PEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ventana de próximas renovaciones
    |--------------------------------------------------------------------------
    |
    | Cantidad de días hacia adelante que se consideran al listar las próximas
    | renovaciones del dashboard de administración.
    |
    */

    'renewal_window_days' => (int) env('MEMBERSHIPS_RENEWAL_WINDOW_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Plan destacado
    |--------------------------------------------------------------------------
    |
    | Clave del plan que se marca como "popular" en el dashboard.
    |
    */

    'popular_plan' => env('MEMBERSHIPS_POPULAR_PLAN', 'professional'),
];
