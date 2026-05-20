<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Decision Rubric
    |--------------------------------------------------------------------------
    |
    | Borrowed from CraftBot's proactive-task rubric. Every ActionProposal is
    | scored on five dimensions (Impact, Risk, Cost, Urgency, Confidence),
    | 1-5 each, where 5 is most favourable to proceeding autonomously. The
    | total (out of 25) routes the proposal: auto-execute, human review, or
    | auto-reject.
    |
    | Auto-routing ships OFF. With only `enabled` on, every proposal still
    | gets a visible score but nothing auto-routes — operators opt into
    | auto-execute / auto-reject per deployment.
    |
    */

    'enabled' => env('DECISION_RUBRIC_ENABLED', true),

    'auto_execute' => [
        'enabled' => env('DECISION_RUBRIC_AUTO_EXECUTE', false),
        'threshold' => env('DECISION_RUBRIC_AUTO_EXECUTE_THRESHOLD', 18),
    ],

    'auto_reject' => [
        'enabled' => env('DECISION_RUBRIC_AUTO_REJECT', false),
        'threshold' => env('DECISION_RUBRIC_AUTO_REJECT_THRESHOLD', 8),
    ],

    /*
    | Risk dimension: derived from ActionProposal.risk_level. Higher score =
    | safer = more favourable to auto-proceeding. `critical` is never
    | auto-routed regardless of total score.
    */
    'risk_scores' => [
        'low' => 5,
        'medium' => 3,
        'high' => 2,
        'critical' => 1,
    ],

    /*
    | Cost dimension: bucketed from payload.estimated_credits when present.
    | Each entry is [inclusive_max_credits, score]; the first match wins.
    */
    'cost_buckets' => [
        [1, 5],
        [10, 4],
        [100, 3],
        [1000, 2],
    ],
    'cost_default' => 3,

];
