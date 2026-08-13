<?php

namespace App\Mcp\Protocol;

use Illuminate\Container\Container;

/**
 * Per-request MCP protocol negotiation result.
 *
 * Bound into the container by NegotiateMcpProtocol so JSON-RPC method handlers
 * can tell a stateless 2026-07-28 request from a legacy handshake request
 * without re-parsing the body. Absent on the stdio transport (no HTTP
 * middleware runs there) — always resolve through self::current().
 */
final class ProtocolContext
{
    public const BINDING = 'mcp.protocol';

    /**
     * @param  array<string, mixed>|null  $clientInfo
     * @param  array<string, mixed>|null  $clientCapabilities
     */
    public function __construct(
        public readonly string $version,
        public readonly bool $stateless,
        public readonly ?array $clientInfo = null,
        public readonly ?array $clientCapabilities = null,
    ) {}

    /**
     * Resolve the negotiated context, falling back to the newest supported
     * revision when nothing was negotiated (stdio, console, tests).
     */
    public static function current(): self
    {
        $container = Container::getInstance();

        if ($container->bound(self::BINDING)) {
            $context = $container->make(self::BINDING);

            if ($context instanceof self) {
                return $context;
            }
        }

        return new self(
            version: ProtocolVersions::SUPPORTED[0],
            stateless: true,
        );
    }

    public function supportsCacheHints(): bool
    {
        return ProtocolVersions::supportsCacheHints($this->version);
    }
}
