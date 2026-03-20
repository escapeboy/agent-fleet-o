<?php

namespace App\Mcp\Concerns;

use App\Mcp\Services\McpAppsCapability;

/**
 * Adds MCP Apps UI metadata to a Tool when the connecting client supports MCP Apps.
 *
 * Usage:
 *   class MyTool extends Tool {
 *       use HasMcpAppUi;
 *       protected function uiResourceUri(): string { return 'ui://fleetq/my-app'; }
 *   }
 *
 * When the client declares io.modelcontextprotocol/ui capability during initialize,
 * the tool's serialized definition will include:
 *   "_meta": { "ui": { "resourceUri": "ui://fleetq/my-app" } }
 *
 * Non-supporting clients receive the tool definition without _meta.ui — they are
 * spec-guaranteed to ignore unknown metadata fields, so there is no regression.
 */
trait HasMcpAppUi
{
    /**
     * The ui:// URI of the HTML resource that renders for this tool.
     * Multiple tools may share the same resource URI (the host deduplicates).
     */
    abstract protected function uiResourceUri(): string;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if (McpAppsCapability::active()) {
            $this->setMeta('ui', ['resourceUri' => $this->uiResourceUri()]);
        }

        return parent::toArray();
    }
}
