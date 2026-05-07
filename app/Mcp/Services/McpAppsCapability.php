<?php

namespace App\Mcp\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Tracks whether a given MCP session supports the MCP Apps extension.
 *
 * On initialize, clients that support MCP Apps declare:
 *   capabilities.extensions["io.modelcontextprotocol/ui"].mimeTypes = ["text/html;profile=mcp-app"]
 *
 * We store a per-session boolean in Redis keyed by session ID (30 min TTL).
 * During tools/list and resources/list, mcp.request is bound in the container,
 * so we can resolve the current session's capability flag.
 */
class McpAppsCapability
{
    public const EXTENSION_ID = 'io.modelcontextprotocol/ui';

    public const MIME_TYPE = 'text/html;profile=mcp-app';

    private const TTL = 1800; // 30 minutes

    private const CACHE_PREFIX = 'mcp.apps.session.';

    /**
     * Store whether a session supports MCP Apps based on the initialize capabilities payload.
     */
    public static function store(string $sessionId, ?array $capabilities): void
    {
        $uiExt = $capabilities['extensions'][self::EXTENSION_ID] ?? null;

        $supported = $uiExt !== null
            && in_array(self::MIME_TYPE, (array) ($uiExt['mimeTypes'] ?? []), true);

        Cache::store()->put(self::CACHE_PREFIX.$sessionId, $supported, self::TTL);
    }

    /**
     * Check whether a specific session supports MCP Apps.
     */
    public static function for(?string $sessionId): bool
    {
        if ($sessionId === null) {
            return false;
        }

        return (bool) Cache::store()->get(self::CACHE_PREFIX.$sessionId, false);
    }

    /**
     * Check whether the CURRENT request's session supports MCP Apps.
     * Only valid during MCP method handling (tools/list, resources/list, tools/call, etc.)
     * where mcp.request is bound in the container.
     */
    public static function active(): bool
    {
        if (! app()->bound('mcp.request')) {
            return false;
        }

        return self::for(app('mcp.request')->sessionId());
    }
}
