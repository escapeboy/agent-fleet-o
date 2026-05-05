<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Agent SLA Configuration
|--------------------------------------------------------------------------
|
| Per-agent SLA panel computes a 0-1 health score from three components,
| each weighted by a knob below. Defaults reflect the P1 hand-tuned
| heuristics; revisit with real production baselines.
|
| Score components:
|   - success: success_rate / 100
|   - latency: linearly degrades from 1.0 to 0.0 between
|              `latency.healthy_ms` and `latency.degraded_ms`
|   - cost:    linearly degrades from 1.0 to 0.0 between
|              `cost.healthy_credits` and `cost.degraded_credits`
|
| Weights MUST sum to 1.0.
|
*/

return [
    'period_days' => env('AGENT_SLA_PERIOD_DAYS', 7),

    'cache_ttl_seconds' => env('AGENT_SLA_CACHE_TTL', 300),

    'weights' => [
        'success' => (float) env('AGENT_SLA_WEIGHT_SUCCESS', 0.6),
        'latency' => (float) env('AGENT_SLA_WEIGHT_LATENCY', 0.2),
        'cost' => (float) env('AGENT_SLA_WEIGHT_COST', 0.2),
    ],

    'latency' => [
        // p95 latency at or below `healthy_ms` scores 1.0; above `degraded_ms` scores 0.0.
        'healthy_ms' => (int) env('AGENT_SLA_LATENCY_HEALTHY_MS', 5000),
        'degraded_ms' => (int) env('AGENT_SLA_LATENCY_DEGRADED_MS', 60000),
    ],

    'cost' => [
        // Avg cost at or below `healthy_credits` scores 1.0; above `degraded_credits` scores 0.0.
        'healthy_credits' => (int) env('AGENT_SLA_COST_HEALTHY_CREDITS', 50),
        'degraded_credits' => (int) env('AGENT_SLA_COST_DEGRADED_CREDITS', 1000),
    ],

    'palette' => [
        'healthy_min' => (float) env('AGENT_SLA_PALETTE_HEALTHY', 0.8),
        'amber_min' => (float) env('AGENT_SLA_PALETTE_AMBER', 0.5),
    ],
];
