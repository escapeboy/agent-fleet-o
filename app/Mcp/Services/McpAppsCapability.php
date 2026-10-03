<?php

namespace App\Mcp\Services;

use App\Mcp\Protocol\ProtocolContext;
use App\Mcp\Protocol\ProtocolVersions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Tracks whether a given MCP session supports the MCP Apps extension.
 *
 * On initialize, clients that support MCP Apps declare:
 *   capabilities.extensions["io.modelcontextprotocol/ui"].mimeTypes = ["text/html;profile=mcp-app"]
 *
 * We store a per-session boolean in Redis keyed by the session id FleetQ issues
 * at initialize (30 min TTL); see active() for how each client kind is resolved.
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
        Cache::store()->put(self::CACHE_PREFIX.$sessionId, self::supports($capabilities), self::TTL);
    }

    public const ISSUED_SESSION_BINDING = 'mcp.issued_session_id';

    /** stdio: one process is one client, so the handshake result lives here. */
    private static ?bool $processSupports = null;

    /**
     * Record the capabilities a legacy client declared in `initialize`.
     *
     * laravel/mcp 1.0 no longer issues protocol sessions, so FleetQ issues its own
     * id for HTTP clients (NegotiateMcpProtocol returns it as Mcp-Session-Id, the
     * client echoes it back) and keeps a process flag for stdio.
     */
    public static function recordInitialize(?array $capabilities): void
    {
        $sessionId = (string) Str::uuid();
        self::store($sessionId, $capabilities);
        self::$processSupports = self::supports($capabilities);
        app()->instance(self::ISSUED_SESSION_BINDING, $sessionId);
    }

    /**
     * @param  array<string, mixed>|null  $capabilities
     */
    public static function supports(?array $capabilities): bool
    {
        $uiExt = $capabilities['extensions'][self::EXTENSION_ID] ?? null;

        return is_array($uiExt) && in_array(self::MIME_TYPE, (array) ($uiExt['mimeTypes'] ?? []), true);
    }

    /**
     * Check whether a specific session supports MCP Apps.
     */
    public static function for(?string $sessionId): bool
    {
        if ($sessionId === null || $sessionId === '') {
            return false;
        }

        return (bool) Cache::store()->get(self::CACHE_PREFIX.$sessionId, false);
    }

    /**
     * Whether the client behind the current request supports MCP Apps.
     *
     * 2026-07-28 clients declare capabilities in every request's _meta; legacy
     * HTTP clients are looked up by the session id FleetQ issued at initialize;
     * stdio uses the process flag.
     */
    public static function active(): bool
    {
        if (app()->bound(ProtocolContext::BINDING)) {
            $context = ProtocolContext::current();

            if ($context->stateless) {
                return self::supports($context->clientCapabilities);
            }

            return self::for(request()->header(ProtocolVersions::HEADER_SESSION_ID));
        }

        return self::$processSupports ?? false;
    }
}
