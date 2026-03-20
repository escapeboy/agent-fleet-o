<?php

namespace App\Mcp;

use App\Mcp\Services\McpAppsCapability;
use Laravel\Mcp\Server\Resource;

/**
 * Base class for MCP App resources — interactive HTML interfaces rendered
 * by MCP Apps-capable hosts (Claude Desktop, Claude.ai, VS Code Copilot, etc.)
 * inside sandboxed iframes directly in the conversation.
 *
 * Resources using this base class are hidden from clients that do not declare
 * the MCP Apps capability during the initialize handshake, avoiding confusion
 * in resources/list responses for non-supporting agents.
 *
 * Subclasses set $uri to a ui:// URI and implement handle() to return HTML.
 */
abstract class McpAppResource extends Resource
{
    /** MCP Apps MIME type — signals to the host that this is an app resource */
    protected string $mimeType = 'text/html;profile=mcp-app';

    /**
     * Only expose this resource to clients that support MCP Apps.
     * Called by eligibleForRegistration() during tools/list and resources/list.
     */
    public function shouldRegister(): bool
    {
        return McpAppsCapability::active();
    }
}
