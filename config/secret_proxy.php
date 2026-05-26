<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Proxy-based secret injection (claude-code-vps)
    |--------------------------------------------------------------------------
    |
    | When enabled, claude-code-vps agent runs receive only an opaque, run-scoped
    | token instead of raw secrets. The secret-proxy Go daemon swaps the opaque
    | token for the real Anthropic OAuth token / MCP bearer at request time and
    | enforces a per-run egress allowlist.
    |
    | Flag OFF (default) = legacy behaviour: real secrets are injected directly
    | into the agent env / .claude.json. Toggling SECRET_PROXY_ENABLED is the
    | intended 30-second rollback path — no code revert required.
    |
    | See docs/architecture/architecture-secret-proxy-injection.md.
    |
    */

    'enabled' => (bool) env('SECRET_PROXY_ENABLED', false),

    // How the in-container `claude` process reaches the daemon. On the shared
    // VPS Docker network use the container_name, NOT the service name, to avoid
    // cross-stack DNS collisions (e.g. http://agent-fleet-secret-proxy:8099).
    'base_url' => env('SECRET_PROXY_BASE_URL'),

    // base64 of 32 random bytes, shared with the daemon. MUST NOT be APP_KEY.
    'key' => env('SECRET_PROXY_KEY'),

    // Added to the run timeout to size the Redis vault TTL.
    'vault_ttl_margin' => (int) env('SECRET_PROXY_TTL_MARGIN', 120),

    // Default-deny egress when true (recommended). The daemon honours its own
    // SECRET_PROXY_ALLOWLIST_STRICT env independently.
    'allowlist_strict' => (bool) env('SECRET_PROXY_ALLOWLIST_STRICT', true),

    // Dedicated Redis connection with an empty key prefix so the Go daemon can
    // read raw keys (mirrors the existing `bridge` connection convention).
    'redis_connection' => env('SECRET_PROXY_REDIS_CONNECTION', 'secret_proxy'),

];
