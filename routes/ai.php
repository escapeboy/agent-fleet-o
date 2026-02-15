<?php

use App\Mcp\Servers\AgentFleetServer;
use Laravel\Mcp\Facades\Mcp;

// Web MCP endpoint (HTTP/SSE) — protected by Sanctum
Mcp::web('/mcp', AgentFleetServer::class)
    ->middleware(['auth:sanctum']);

// Local MCP server (stdio) — for CLI agents like Codex, Claude Code
Mcp::local('agent-fleet', AgentFleetServer::class);
