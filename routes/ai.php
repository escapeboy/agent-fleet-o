<?php

use App\Mcp\Servers\AgentFleetServer;
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
    'resource' => url('/'.$path),
    'authorization_servers' => [url('/')],
    'scopes_supported' => ['mcp:use'],
]))->where('path', '.*')->name('mcp.oauth.protected-resource');

// RFC 8414 — OAuth 2.0 Authorization Server Metadata
Route::get('/.well-known/oauth-authorization-server/{path?}', fn (?string $path = '') => response()->json([
    'issuer' => url('/'),
    'authorization_endpoint' => route('passport.authorizations.authorize'),
    'token_endpoint' => route('passport.token'),
    'registration_endpoint' => url('oauth/register'),
    'response_types_supported' => ['code'],
    'code_challenge_methods_supported' => ['S256'],
    'scopes_supported' => ['mcp:use'],
    'grant_types_supported' => ['authorization_code', 'refresh_token'],
]))->where('path', '.*')->name('mcp.oauth.authorization-server');

// RFC 7591 — Dynamic Client Registration
Route::post('oauth/register', OAuthRegisterController::class);

// Web MCP endpoint (HTTP/SSE) — protected by Passport OAuth2 (Authorization Code + PKCE)
Mcp::web('/mcp', AgentFleetServer::class)
    ->middleware(['auth:passport', 'scope:mcp:use']);

// Local MCP server (stdio) — for CLI agents like Codex, Claude Code (unaffected)
Mcp::local('agent-fleet', AgentFleetServer::class);
