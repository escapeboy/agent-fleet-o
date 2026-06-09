<?php

declare(strict_types=1);

return [
    'protocol_version' => 'asi1-v1',
    'protocol_manifest_uri' => 'proto:30a801ed3a83f9a0ff0a9f1e6fe958cb91da1fc2218b153df7b6cbf87bd33d62',
    'fleetq_extension_uri' => 'proto:fleetq-ext/v1',

    'inbound' => [
        'max_body_bytes' => 512_000,
        'clock_skew_tolerance_seconds' => 300,
        'replay_window_hours' => 24,
        'rate_limit_per_remote_per_minute' => 60,
        'rate_limit_per_agent_per_minute' => 300,
        'sync_response_timeout_seconds' => 30,
    ],

    'outbound' => [
        'timeout_seconds' => 30,
        'max_timeout_seconds' => 120,
        'retries' => 3,
        'retry_base_ms' => 100,
        'retry_multiplier' => 4,
        'circuit_breaker_failure_threshold' => 5,
        'circuit_breaker_window_seconds' => 60,
        'circuit_breaker_open_seconds' => 120,
    ],

    'manifest' => [
        'cache_seconds' => 300,
    ],

    'a2a' => [
        // Consumer-side A2A discovery (fetching an external agent's AgentCard).
        // Off by default — flip via env to enable.
        'discovery_enabled' => env('A2A_DISCOVERY_ENABLED', false),
        'well_known_path' => '/.well-known/agent-card.json',

        // Consumer-side A2A dispatch (calling a discovered A2A agent over
        // JSON-RPC message/send). Independent of discovery: with this off, A2A
        // agents stay discoverable but not callable (ProtocolDispatcher throws).
        'dispatch_enabled' => env('A2A_DISPATCH_ENABLED', false),

        // Max times to poll tasks/get for a long-running A2A Task before giving
        // up, and the delay between polls (ms). Bounds total wait alongside the
        // outbound timeout.
        'task_poll_attempts' => (int) env('A2A_TASK_POLL_ATTEMPTS', 5),
        'task_poll_delay_ms' => (int) env('A2A_TASK_POLL_DELAY_MS', 1000),
    ],

    'session' => [
        'idle_ttl_seconds' => 86_400,
    ],
];
