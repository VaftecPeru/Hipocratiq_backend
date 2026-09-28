<?php

return [
    'ai' => [
        'enabled' => (bool) env('EPIDEMIOLOGICAL_AI_ENABLED', false),
        'provider' => env('EPIDEMIOLOGICAL_AI_PROVIDER', 'unavailable'),
        'base_url' => env('EPIDEMIOLOGICAL_AI_BASE_URL', 'https://api.openai.com/v1'),
        'api_key' => env('EPIDEMIOLOGICAL_AI_API_KEY'),
        'model' => env('EPIDEMIOLOGICAL_AI_MODEL'),
        'timeout_seconds' => (int) env('EPIDEMIOLOGICAL_AI_TIMEOUT_SECONDS', 20),
        'max_output_tokens' => (int) env('EPIDEMIOLOGICAL_AI_MAX_OUTPUT_TOKENS', 1200),
        'structured_output' => (bool) env('EPIDEMIOLOGICAL_AI_STRUCTURED_OUTPUT', true),
    ],
    'imaging' => [
        'provider' => env('IMAGING_PROVIDER', 'unavailable'),
        'dicomweb_base_url' => env('IMAGING_DICOMWEB_BASE_URL'),
        'viewer_base_url' => env('IMAGING_VIEWER_BASE_URL'),
    ],
    'telemedicine' => [
        'provider' => env('TELEMEDICINE_PROVIDER', 'unavailable'),
        'base_url' => env('TELEMEDICINE_BASE_URL'),
        'room_prefix' => env('TELEMEDICINE_ROOM_PREFIX', 'hipocratiq'),
        'room_secret' => env('TELEMEDICINE_ROOM_SECRET'),
    ],
    'whatsapp' => [
        'driver' => env('WHATSAPP_DRIVER', 'unavailable'),
        'base_url' => env('WHATSAPP_BASE_URL', 'https://graph.facebook.com'),
        'api_version' => env('WHATSAPP_API_VERSION'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'appointment_template' => env('WHATSAPP_APPOINTMENT_TEMPLATE'),
        'appointment_language' => env('WHATSAPP_APPOINTMENT_LANGUAGE', 'es_MX'),
        'reminder_minutes' => (int) env('WHATSAPP_APPOINTMENT_REMINDER_MINUTES', 1440),
        'timeout_seconds' => (int) env('WHATSAPP_TIMEOUT_SECONDS', 15),
    ],
];
