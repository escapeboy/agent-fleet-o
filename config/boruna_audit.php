<?php

return [
    'enabled' => (bool) env('BORUNA_AUDIT_ENABLED', true),
    'storage_disk' => env('BORUNA_STORAGE_DISK', 'boruna_bundles'),
    'shadow_mode' => (bool) env('BORUNA_SHADOW_MODE', true),
    'shadow_threshold' => (float) env('BORUNA_SHADOW_THRESHOLD', 0.05),
    'workflow_timeout_ms' => (int) env('BORUNA_WORKFLOW_TIMEOUT_MS', 5000),
    'default_quota_per_month' => env('BORUNA_DEFAULT_QUOTA_PER_MONTH', 'unlimited'),
    'workflows' => [
        'driver_scoring' => ['enabled' => (bool) env('BORUNA_WORKFLOW_DRIVER_SCORING_ENABLED', true), 'version' => 'v1'],
        'route_approval' => ['enabled' => (bool) env('BORUNA_WORKFLOW_ROUTE_APPROVAL_ENABLED', true), 'version' => 'v1'],
        'incident_classification' => ['enabled' => (bool) env('BORUNA_WORKFLOW_INCIDENT_CLASSIFICATION_ENABLED', true), 'version' => 'v1'],
    ],
    'verification' => [
        'schedule_cron' => env('BORUNA_VERIFY_CRON', '0 3 * * *'),
        'sample_size' => (int) env('BORUNA_VERIFY_SAMPLE', 20),
        'grace_days' => (int) env('BORUNA_BUNDLE_GRACE_DAYS', 90),
    ],
];
