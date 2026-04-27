<?php

declare(strict_types=1);

/**
 * FleetQ public discovery endpoint configuration.
 *
 * Controls which fields are exposed on the unauthenticated `GET /.well-known/fleetq`
 * capability manifest. All flags default to `true` so the community edition is
 * fully self-describing out of the box. Operators that prefer a smaller public
 * surface can flip individual flags to `false` via env vars.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Expose application name
    |--------------------------------------------------------------------------
    |
    | When true, the discovery payload includes `name` from `config('app.name')`.
    |
    */
    'expose_name' => (bool) env('DISCOVERY_EXPOSE_NAME', true),

    /*
    |--------------------------------------------------------------------------
    | Expose installed version
    |--------------------------------------------------------------------------
    |
    | When true, the discovery payload includes `version`, read from the base
    | `composer.json` `version` field (falls back to `'unknown'`).
    |
    */
    'expose_version' => (bool) env('DISCOVERY_EXPOSE_VERSION', true),

    /*
    |--------------------------------------------------------------------------
    | Expose MCP endpoints
    |--------------------------------------------------------------------------
    |
    | When true, the payload includes the `mcp` block (`http_endpoint`,
    | `stdio_command`, transport list).
    |
    */
    'expose_mcp' => (bool) env('DISCOVERY_EXPOSE_MCP', true),

    /*
    |--------------------------------------------------------------------------
    | Expose REST API endpoints
    |--------------------------------------------------------------------------
    |
    | When true, the payload includes the `api` block (`base_url`, `docs_url`).
    |
    */
    'expose_api' => (bool) env('DISCOVERY_EXPOSE_API', true),

    /*
    |--------------------------------------------------------------------------
    | Expose authentication metadata
    |--------------------------------------------------------------------------
    |
    | When true, the payload includes the `auth` block (scheme + token endpoint).
    |
    */
    'expose_auth' => (bool) env('DISCOVERY_EXPOSE_AUTH', true),

    /*
    |--------------------------------------------------------------------------
    | Expose MCP tool count
    |--------------------------------------------------------------------------
    |
    | When true, the payload includes `tools.count` — the number of MCP tools
    | registered on `AgentFleetServer`. Useful for clients that want a quick
    | "how big is this server" hint without auth.
    |
    */
    'expose_tool_count' => (bool) env('DISCOVERY_EXPOSE_TOOL_COUNT', true),

    /*
    |--------------------------------------------------------------------------
    | Expose generation timestamp
    |--------------------------------------------------------------------------
    |
    | When true, the payload includes `generated_at` (ISO-8601, UTC).
    |
    */
    'expose_generated_at' => (bool) env('DISCOVERY_EXPOSE_GENERATED_AT', true),

];
