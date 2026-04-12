<?php

namespace App\Mcp\Attributes;

use Attribute;

/**
 * Marks an MCP tool class for automatic inclusion in the in-app assistant.
 *
 * Usage:
 *   #[AssistantTool('read')]        — always available
 *   #[AssistantTool('write')]       — Member+ only
 *   #[AssistantTool('destructive')] — Admin/Owner only
 */
#[Attribute(Attribute::TARGET_CLASS)]
class AssistantTool
{
    public function __construct(public readonly string $tier = 'read') {}
}
