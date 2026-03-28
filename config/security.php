<?php

return [

    /*
    |--------------------------------------------------------------------------
    | IP Reputation (AbuseIPDB)
    |--------------------------------------------------------------------------
    */
    'ip_reputation' => [
        'enabled' => env('IP_REPUTATION_ENABLED', true),

        // AbuseIPDB confidence score threshold (0–100) above which the request
        // is considered high-risk. Requests from IPs above this threshold are
        // blocked when log_only is false.
        'block_threshold' => env('IP_REPUTATION_BLOCK_THRESHOLD', 75),

        // When true, high-risk IPs are logged but not blocked. Useful for
        // monitoring before enabling enforcement.
        'log_only' => env('IP_REPUTATION_LOG_ONLY', false),

        // AbuseIPDB v2 API key. Free tier: 1,000 checks/day.
        'abuseipdb_key' => env('ABUSEIPDB_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Entity Risk Scoring
    |--------------------------------------------------------------------------
    */
    'risk' => [
        // ContactIdentity risk_score threshold that triggers a security review.
        'review_threshold' => env('SECURITY_REVIEW_THRESHOLD', 30),
    ],

];
