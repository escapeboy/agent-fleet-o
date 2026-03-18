<?php

use App\Mcp\Servers\AgentFleetServer;
use Laravel\Mcp\Facades\Mcp;

// OAuth2 discovery + dynamic client registration (RFC 8414, RFC 7591)
// Must be registered before the MCP web route so the /.well-known/* routes resolve first.
Mcp::oauthRoutes();

// Web MCP endpoint (HTTP/SSE) — protected by Passport OAuth2 (Authorization Code + PKCE)
Mcp::web('/mcp', AgentFleetServer::class)
    ->middleware(['auth:passport', 'scope:mcp:use']);

// Local MCP server (stdio) — for CLI agents like Codex, Claude Code (unaffected)
Mcp::local('agent-fleet', AgentFleetServer::class);
