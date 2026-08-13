<?php

namespace App\Mcp\Protocol;

/**
 * Single source of truth for the MCP protocol versions FleetQ speaks.
 *
 * The 2026-07-28 revision removes the initialize/initialized handshake
 * (SEP-2575) and protocol-level sessions (SEP-2567): every request carries its
 * own negotiation data in `params._meta` and must be servable statelessly.
 * Older revisions keep the handshake and the Mcp-Session-Id header, so both
 * formats have to be detected per request during the transition.
 */
final class ProtocolVersions
{
    public const V2026_07_28 = '2026-07-28';

    public const V2025_11_25 = '2025-11-25';

    public const V2025_06_18 = '2025-06-18';

    public const V2025_03_26 = '2025-03-26';

    public const V2024_11_05 = '2024-11-05';

    /**
     * Newest first — element 0 is what the server advertises by default.
     *
     * @var array<int, string>
     */
    public const SUPPORTED = [
        self::V2026_07_28,
        self::V2025_11_25,
        self::V2025_06_18,
        self::V2025_03_26,
        self::V2024_11_05,
    ];

    /**
     * Assumed when a client sends no version at all, per the Streamable HTTP
     * transport spec ("if absent, servers SHOULD assume 2025-03-26").
     */
    public const FALLBACK = self::V2025_03_26;

    /** First revision where negotiation data travels in `params._meta` (SEP-2575). */
    public const STATELESS_SINCE = self::V2026_07_28;

    /** First revision where tools/list may carry cache hints (SEP-2549). */
    public const CACHE_HINTS_SINCE = self::V2026_07_28;

    // SEP-2575 — `params._meta` keys carried on every request.
    public const META_PROTOCOL_VERSION = 'io.modelcontextprotocol/protocolVersion';

    public const META_CLIENT_INFO = 'io.modelcontextprotocol/clientInfo';

    public const META_CLIENT_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';

    // SEP-2243 — Streamable HTTP request headers.
    public const HEADER_PROTOCOL_VERSION = 'MCP-Protocol-Version';

    public const HEADER_METHOD = 'Mcp-Method';

    public const HEADER_NAME = 'Mcp-Name';

    // SEP-2567 — removed in 2026-07-28, still honoured for older clients.
    public const HEADER_SESSION_ID = 'Mcp-Session-Id';

    public static function supports(?string $version): bool
    {
        return $version !== null && in_array($version, self::SUPPORTED, true);
    }

    /**
     * Revisions are ISO dates, so lexicographic comparison is chronological.
     */
    public static function isAtLeast(string $version, string $floor): bool
    {
        return $version >= $floor;
    }

    public static function isStateless(string $version): bool
    {
        return self::isAtLeast($version, self::STATELESS_SINCE);
    }

    public static function supportsCacheHints(string $version): bool
    {
        return self::isAtLeast($version, self::CACHE_HINTS_SINCE);
    }
}
