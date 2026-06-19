<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Upstream credit runway alerts
    |--------------------------------------------------------------------------
    |
    | Monitors platform-funded (sub-program) LLM spend per (sub_program, provider)
    | and emails the platform owner when the forecasted days-to-depletion crosses
    | a threshold. Budgets/recipient/thresholds/cooldown can be overridden at
    | runtime via the matching GlobalSetting keys (see CheckUpstreamCreditRunwayAction).
    |
    */

    'enabled' => (bool) env('UPSTREAM_CREDIT_ALERTS_ENABLED', true),

    // Where the alert email goes. Falls back to the app's from-address.
    'recipient' => env('UPSTREAM_CREDIT_ALERT_EMAIL') ?: env('MAIL_FROM_ADDRESS'),

    // Days-to-depletion ladder. An alert fires for the tightest bucket the runway
    // is at or below; a smaller bucket re-alerts even within cooldown of a larger one.
    'threshold_days' => [14, 7, 3],

    // Minimum hours between identical (sub_program, provider, bucket) alerts.
    'cooldown_hours' => (int) env('UPSTREAM_CREDIT_ALERT_COOLDOWN_HOURS', 24),

    // Upstream allotments. 'credits' = amount topped up at the provider (1 credit =
    // $0.001); 'since' = the date the allotment started (spend counted from then).
    // After reloading the provider, bump 'credits' and 'since'. Prefer the
    // GlobalSetting 'upstream_credit_budgets' for runtime edits without a deploy.
    //
    // 'budgets' => [
    //     'finance' => [
    //         'anthropic' => ['credits' => 5_000_000, 'since' => '2026-06-01'],
    //         'openai'    => ['credits' => 2_000_000, 'since' => '2026-06-01'],
    //     ],
    // ],
    'budgets' => [],
];
