<?php

namespace App\Domain\Tool\Enums;

enum ToolType: string
{
    case McpStdio = 'mcp_stdio';
    case McpHttp = 'mcp_http';
    case McpBridge = 'mcp_bridge';
    case BuiltIn = 'built_in';
    case Workflow = 'workflow';
    case ComputeEndpoint = 'compute_endpoint';

    public function label(): string
    {
        return match ($this) {
            self::McpStdio => 'MCP Server (stdio)',
            self::McpHttp => 'MCP Server (HTTP)',
            self::McpBridge => 'MCP Server (Bridge)',
            self::BuiltIn => 'Built-in Host Tool',
            self::Workflow => 'Workflow',
            self::ComputeEndpoint => 'GPU Compute Endpoint',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::McpStdio => 'bg-indigo-100 text-indigo-800',
            self::McpHttp => 'bg-blue-100 text-blue-800',
            self::McpBridge => 'bg-purple-100 text-purple-800',
            self::BuiltIn => 'bg-amber-100 text-amber-800',
            self::Workflow => 'bg-emerald-100 text-emerald-800',
            self::ComputeEndpoint => 'bg-rose-100 text-rose-800',
        };
    }

    public function isCompute(): bool
    {
        return $this === self::ComputeEndpoint;
    }

    public function isMcp(): bool
    {
        return in_array($this, [self::McpStdio, self::McpHttp, self::McpBridge]);
    }
}
