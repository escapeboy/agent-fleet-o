<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Policy-Governed Autonomy
    |--------------------------------------------------------------------------
    |
    | Master switch for versioned AgentPolicy governance. Ships OFF: with this
    | false the AgentPolicyResolver always returns null and every gate
    | (GitOperationGate, IntegrationActionGate) plus the DecisionRubric path
    | keep their existing behavior byte-for-byte. Turning it on still changes
    | nothing until a team creates an *enabled* policy — so activation is a
    | flag flip and rollback is the reverse, matching the 30-second rollback
    | requirement for autonomy features.
    |
    */

    'enabled' => env('AGENT_POLICIES_ENABLED', false),

    /*
    | Default rules used to seed a brand-new policy's first version. Mirrors
    | the safest posture: nothing auto-executes, migrations are denied,
    | high+ risk is held for review.
    */
    'default_rules' => [
        'allowed_target_types' => null, // null = all target types permitted (subject to the rest)
        'denied_target_types' => ['migration'],
        'risk_ceiling' => 'medium',
        'auto_execute' => ['enabled' => false, 'threshold' => 18],
        'spend_cap' => null,     // e.g. ['credits' => 5000, 'window' => 'day']
        'frequency_cap' => null, // e.g. ['count' => 50, 'window' => 'day']
        'sensitive_paths' => [],
    ],

];
