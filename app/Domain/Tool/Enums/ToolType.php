<?php

namespace App\Domain\Tool\Enums;

enum ToolType: string
{
    case McpStdio = 'mcp_stdio';
    case McpHttp = 'mcp_http';
    case BuiltIn = 'built_in';

    public function label(): string
    {
        return match ($this) {
            self::McpStdio => 'MCP Server (stdio)',
            self::McpHttp => 'MCP Server (HTTP)',
            self::BuiltIn => 'Built-in Host Tool',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::McpStdio => 'bg-indigo-100 text-indigo-800',
            self::McpHttp => 'bg-blue-100 text-blue-800',
            self::BuiltIn => 'bg-amber-100 text-amber-800',
        };
    }

    public function isMcp(): bool
    {
        return in_array($this, [self::McpStdio, self::McpHttp]);
    }
}
