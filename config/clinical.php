<?php

return [
    'consent_policy_version' => env('CLINICAL_CONSENT_POLICY_VERSION', '2026-09'),
    'epidemiology' => [
        'minimum_external_cohort_size' => (int) env('EPIDEMIOLOGY_MINIMUM_EXTERNAL_COHORT_SIZE', 10),
    ],
];
