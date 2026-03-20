<?php

use App\Http\Controllers\OAuthRevokeController;
use App\Mcp\Servers\AgentFleetServer;
use App\Mcp\Servers\CompactMcpServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;
use Laravel\Mcp\Server\Registrar;

// OAuth2 discovery + dynamic client registration (RFC 8414, RFC 7591)
// Must be registered before the MCP web route so the /.well-known/* routes resolve first.
//
// NOTE: We do NOT use Mcp::oauthRoutes() because it has a bug where
// `authorization_servers` is set to the protected resource URL instead of
// the issuer URL. This breaks Claude.ai's OAuth discovery chain.
// See: https://github.com/laravel/mcp/issues — authorization_servers must be
// [url('/')] (the issuer), NOT [url('/mcp')] (the protected resource).

Registrar::ensureMcpScope();

// RFC 9728 — OAuth 2.0 Protected Resource Metadata
// authorization_servers MUST point to the issuer (OAuth server root), not the MCP endpoint.
Route::get('/.well-known/oauth-protected-resource/{path?}', fn (?string $path = '') => response()->json([
    'resource' => url('/'.ltrim($path ?? '', '/')),
    'authorization_servers' => [url('/')],
    'bearer_methods_supported' => ['header'],
    'scopes_supported' => ['mcp:use'],
]))->where('path', '.*')->name('mcp.oauth.protected-resource');

// RFC 8414 — OAuth 2.0 Authorization Server Metadata
Route::get('/.well-known/oauth-authorization-server/{path?}', fn (?string $path = '') => response()->json([
    'issuer' => url('/'),
    'authorization_endpoint' => route('passport.authorizations.authorize'),
    'token_endpoint' => route('passport.token'),
    'registration_endpoint' => url('oauth/register'),
    'revocation_endpoint' => url('oauth/revoke'),
    'response_types_supported' => ['code'],
    'grant_types_supported' => ['authorization_code', 'refresh_token'],
    'code_challenge_methods_supported' => ['S256'],
    'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post'],
    'scopes_supported' => ['mcp:use'],
]))->where('path', '.*')->name('mcp.oauth.authorization-server');

// RFC 7591 — Dynamic Client Registration
// Rate limited to 20 requests per hour per IP.
Route::post('oauth/register', OAuthRegisterController::class)
    ->middleware('throttle:20,60');

// RFC 7009 — OAuth 2.0 Token Revocation
// Always returns 200, even for invalid/unknown tokens (per spec).
Route::post('oauth/revoke', OAuthRevokeController::class)
    ->middleware('throttle:120,1');

// Compact MCP endpoint (HTTP/SSE) — 33 consolidated tools for remote clients (Claude.ai)
// Each tool supports multiple actions via "action" parameter, delegating to original tools.
Mcp::web('/mcp', CompactMcpServer::class)
    ->middleware(['auth:passport', 'scope:mcp:use']);

// Full MCP endpoint (HTTP/SSE) — all 259 tools for power users and clients without tool limits
Mcp::web('/mcp/full', AgentFleetServer::class)
    ->middleware(['auth:passport', 'scope:mcp:use']);

// Local MCP server (stdio) — for CLI agents like Codex, Claude Code (no tool limit)
Mcp::local('agent-fleet', AgentFleetServer::class);
