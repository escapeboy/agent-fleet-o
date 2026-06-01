<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Fan-out cost pre-flight gate
    |--------------------------------------------------------------------------
    | When enabled, a crew/workflow run whose estimated cost exceeds the
    | threshold must be explicitly confirmed before it dispatches. Disabled by
    | default — turning it on is a deliberate, reversible env flip.
    */
    'cost_gate' => [
        'enabled' => env('ORCHESTRATION_COST_GATE_ENABLED', false),

        // Projected credits above which confirmation is required.
        // A team may override via settings['cost_gate_threshold_credits'].
        'threshold_credits' => (int) env('ORCHESTRATION_COST_GATE_THRESHOLD', 5000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Orchestration tier selector
    |--------------------------------------------------------------------------
    | When enabled, the platform recommends an orchestration shape (single
    | agent / crew / workflow) for a goal. Recommendation only — it never
    | auto-executes anything.
    */
    'tier_selector' => [
        'enabled' => env('ORCHESTRATION_TIER_SELECTOR_ENABLED', false),
    ],
];
