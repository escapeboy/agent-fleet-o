<?php

namespace App\Mcp\Listeners;

use App\Mcp\Services\McpAppsCapability;
use Laravel\Mcp\Events\SessionInitialized;

/**
 * Listens for the MCP initialize handshake and records whether the connecting
 * client supports the MCP Apps extension (io.modelcontextprotocol/ui).
 *
 * The capability flag is stored in Redis keyed by session ID so that subsequent
 * tools/list and resources/list requests can conditionally expose UI resources.
 */
class McpAppsCapabilityListener
{
    public function handle(SessionInitialized $event): void
    {
        McpAppsCapability::store($event->sessionId, $event->clientCapabilities);
    }
}
