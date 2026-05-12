<?php

declare(strict_types=1);

/**
 * Platform observability configuration.
 *
 * Controls:
 *   - Sentry deep-link URL composition (org/project slugs + template)
 *   - Prometheus exporter (storage adapter, top-N team rollup)
 *   - Internal /metrics endpoint allowlist
 *   - Alerting thresholds + recipient list
 *   - Grafana iframe target for the super-admin monitoring page
 *
 * All env reads are gracefully optional — code paths that depend on a setting
 * short-circuit when its env is empty (e.g. no SENTRY_ORG_SLUG → no deep link
 * rendered, but error capture still works).
 */
return [
    'sentry' => [
        // Organization slug as it appears in Sentry URLs. Optional.
        'org_slug' => env('SENTRY_ORG_SLUG'),

        // Project slug for direct event URLs. Optional. Falls back to issue search.
        'project_slug' => env('SENTRY_PROJECT_SLUG'),

        // URL template for issue search. Tokens: {org}, {query}.
        'issue_search_url_template' => env(
            'SENTRY_ISSUE_SEARCH_URL_TEMPLATE',
            'https://sentry.io/organizations/{org}/issues/?query={query}',
        ),

        // URL template for direct event link. Tokens: {org}, {project}, {event_id}.
        'event_url_template' => env(
            'SENTRY_EVENT_URL_TEMPLATE',
            'https://sentry.io/organizations/{org}/projects/{project}/events/{event_id}/',
        ),
    ],

    'prometheus' => [
        // Master switch. When false, the registry returns a noop emitter.
        'enabled' => filter_var(env('PROMETHEUS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

        // Storage adapter for the Prometheus client. Allowed: 'redis', 'apc', 'in_memory'.
        // In test env we force in_memory regardless (see ObservabilityServiceProvider).
        'storage' => env('PROMETHEUS_STORAGE_ADAPTER', 'redis'),

        // Laravel Redis connection name (matches config/database.php redis.connections).
        // We reuse the 'cache' connection (DB 1) to avoid mixing metrics with queues (DB 0).
        'redis_connection' => env('PROMETHEUS_REDIS_CONNECTION', 'cache'),

        // Top-N team_id labels emitted as full UUIDs; remainder rolled up under 'other'.
        // Prevents Prometheus label cardinality blowup as team count grows.
        'top_n_teams' => (int) env('OBSERVABILITY_TOP_N_TEAMS', 50),

        // Refresh interval for the top-N sorted set (seconds). Should match
        // the metrics:sample cron cadence.
        'top_n_refresh_seconds' => (int) env('OBSERVABILITY_TOP_N_REFRESH_SECONDS', 60),

        // Comma-separated extra IPs allowed to scrape /metrics. Docker bridge
        // networks (172.16.0.0/12) and localhost are always allowed.
        'metrics_allowed_ips' => env('OBSERVABILITY_METRICS_ALLOWED_IPS', ''),

        // Histogram bucket boundaries for LLM latency (milliseconds).
        'llm_latency_buckets_ms' => [100, 250, 500, 1000, 2500, 5000, 10000, 30000, 60000, 120000],
    ],

    'alerts' => [
        // Comma-separated email recipients. Empty disables Laravel-side alerting
        // (Grafana alerting in Sprint 3 still works independently if configured).
        'recipients' => env('ADMIN_ALERT_EMAILS', ''),

        // Per-rule dedup window. Prevents alert storms when a breach persists.
        'cooldown_seconds' => (int) env('ALERT_COOLDOWN_SECONDS', 600),

        // Subject line prefix for alert emails.
        'email_subject_prefix' => env('ALERT_EMAIL_SUBJECT_PREFIX', '[FleetQ Alert]'),

        // Thresholds. A breach is "current value >= threshold".
        'thresholds' => [
            'queue_depth' => (int) env('ALERT_QUEUE_DEPTH', 500),
            'error_rate_per_minute' => (int) env('ALERT_ERROR_RATE_PER_MINUTE', 30),
            'p95_llm_latency_ms' => (int) env('ALERT_P95_LATENCY_MS', 60_000),
            'stuck_experiments' => (int) env('ALERT_STUCK_EXPERIMENTS', 10),
            'circuit_breaker_open' => (int) env('ALERT_CIRCUIT_BREAKER_OPEN', 1),
        ],

        // Prometheus HTTP API base used by CheckAlertRulesCommand. Empty disables
        // Laravel-side evaluation (only Grafana alerting will fire).
        'prometheus_api_url' => env('PROMETHEUS_API_URL', ''),

        // Ignore open circuit breakers whose last_failure_at is older than this.
        // Zombie breakers (agent failed once, never ran again) would otherwise
        // re-fire the alert every cooldown window forever.
        'breaker_stale_after_seconds' => (int) env('ALERT_BREAKER_STALE_AFTER_SECONDS', 3600),
    ],

    'health' => [
        // When set, EnvironmentCheck fails unless `APP_ENV` matches this value.
        // Set to `production` in prod .env to surface accidental APP_ENV regressions
        // (e.g. cache:clear that leaked dev config). Empty = check disabled.
        'expected_env' => env('OBSERVABILITY_HEALTH_EXPECTED_ENV', ''),
    ],

    'monitoring' => [
        // Grafana base URL behind Cloudflare Access. Used by MonitoringIframePage.
        'grafana_base_url' => env('GRAFANA_BASE_URL', 'https://monitoring.fleetq.net'),

        // Dashboard UID for the platform-health iframe target.
        'platform_health_dashboard_uid' => env('GRAFANA_DASHBOARD_UID_PLATFORM_HEALTH', 'platform-health'),

        // Query string appended to the iframe URL. Kiosk mode hides Grafana chrome.
        'iframe_query' => env('GRAFANA_IFRAME_QUERY', 'kiosk=tv&theme=light&from=now-6h'),
    ],
];
