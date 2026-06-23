<?php

/**
 * Tier-2 runtime feature flags (Laravel Pennant-backed).
 *
 * This is the SECOND flag tier, additive to the existing env/config dark-ship
 * flags (Tier-1). Tier-1 flags stay as plain env booleans flipped at deploy
 * time; Tier-2 flags are team-scoped, support percentage rollout, and can be
 * toggled at runtime without a redeploy.
 *
 * The whole tier is dark-shipped behind `runtime_enabled`: when false,
 * FeatureFlagService::active() returns each definition's static `default` and
 * applies no Pennant override.
 *
 * Each definition:
 *   - label/description/group: human-readable metadata (admin catalog).
 *   - sensitive: drives the approval gate. MUST be true for anything touching
 *     security, billing, or destructive behavior. Agent-driven flips of a
 *     sensitive flag require an ApprovalRequest; non-sensitive flips are
 *     applied immediately.
 *   - default: resolved value when there is no per-team override and the
 *     rollout percentage does not include the team.
 */

return [

    'runtime_enabled' => env('FEATURE_FLAGS_RUNTIME_ENABLED', false),

    'definitions' => [

        'beta_feature' => [
            'label' => 'Beta Feature (demo)',
            'description' => 'Demonstration Tier-2 flag: per-team beta gating layered over percentage rollout.',
            'group' => 'Demo',
            'sensitive' => false,
            'default' => false,
        ],

    ],

];
