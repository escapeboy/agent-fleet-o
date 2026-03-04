<?php

return [

    /*
    |--------------------------------------------------------------------------
    | KMS Provider Pricing
    |--------------------------------------------------------------------------
    |
    | Static pricing data for cost estimation. Prices are in USD.
    | Used to display estimated monthly cost in Team Settings.
    |
    | Formula: key_cost + (estimated_monthly_kms_calls * per_call_price)
    | Where: estimated_monthly_kms_calls = (runs + crew_executions) * (1 - cache_hit_rate)
    |
    */

    'cache_hit_rate' => 0.95, // 95% cache hit rate (conservative)

    'aws_kms' => [
        'key_monthly' => 1.00,          // USD per CMK per month
        'per_10k_requests' => 0.03,     // USD per 10,000 requests
    ],

    'gcp_kms' => [
        'key_version_monthly' => 0.06,  // USD per active key version per month
        'per_10k_operations' => 0.03,   // USD per 10,000 crypto operations
    ],

    'azure_key_vault' => [
        'per_10k_operations' => 0.03,   // Standard tier, per 10,000 operations
    ],

];
