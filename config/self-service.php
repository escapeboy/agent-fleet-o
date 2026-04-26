<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Self-Service Troubleshooting Configuration
|--------------------------------------------------------------------------
|
| Knobs for the customer self-service troubleshooting arc:
|   - <x-fix-with-assistant> diagnose surface
|   - Dashboard health-summary tile
|   - experiment_diagnose MCP tool
|
| Defaults are the hand-tuned values that shipped with the arc; tune via
| env vars if a deployment needs different windows or TTLs.
|
*/

return [
    'diagnose' => [
        // Skill / crew "eligible for diagnosis" window — a failed execution
        // older than this is not surfaced.
        'failure_window_days' => (int) env('SELF_SERVICE_FAILURE_WINDOW_DAYS', 7),

        // Cache TTL for the experiment_diagnose composite tool result, keyed
        // by (experiment, status, updated_at). Short by design — state changes
        // invalidate via the composite key.
        'cache_seconds' => (int) env('SELF_SERVICE_DIAGNOSE_CACHE_SECONDS', 60),

        // Replay history cap — last N replay results stored on the
        // WorkflowSnapshot.metadata.replays array. JSONB-safe upper bound.
        'replay_history_cap' => (int) env('SELF_SERVICE_REPLAY_HISTORY_CAP', 5),
    ],

    'dashboard' => [
        // Cache TTL for the dashboard health-summary tile (per team).
        'tile_cache_seconds' => (int) env('SELF_SERVICE_HEALTH_TILE_CACHE_SECONDS', 30),

        // Window for "failed in last N hours" on the dashboard tile.
        'failed_window_hours' => (int) env('SELF_SERVICE_DASHBOARD_FAILED_WINDOW_HOURS', 24),

        // Default per-state stuck timeout when experiments.recovery.timeouts
        // doesn't have an explicit entry for a state.
        'default_stuck_timeout_seconds' => (int) env('SELF_SERVICE_DEFAULT_STUCK_TIMEOUT_SECONDS', 900),
    ],
];
