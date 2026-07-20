<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Serves /auth.md — the agent-facing description of how to obtain credentials
 * for the FleetQ API and MCP endpoint.
 *
 * Everything documented here is backed by a live endpoint: RFC 9728 protected
 * resource metadata, RFC 8414 authorization server metadata, and RFC 7591
 * dynamic client registration (all registered in routes/ai.php).
 */
class AuthMdController extends Controller
{
    public function __invoke(): Response
    {
        $baseUrl = rtrim(config('app.url'), '/');

        $body = <<<MARKDOWN
        # auth.md

        How autonomous agents authenticate to FleetQ.

        FleetQ exposes two agent-callable surfaces, both protected by bearer
        tokens sent in the `Authorization` header:

        - **MCP endpoint** — `POST {$baseUrl}/mcp` (JSON-RPC over HTTP/SSE).
        - **REST API v1** — `{$baseUrl}/api/v1` ([OpenAPI 3.1]({$baseUrl}/docs/api.json)).

        ## Discovery

        | Document | URL |
        |---|---|
        | Protected Resource Metadata (RFC 9728) | {$baseUrl}/.well-known/oauth-protected-resource |
        | Authorization Server Metadata (RFC 8414) | {$baseUrl}/.well-known/oauth-authorization-server |
        | API catalog (RFC 9727) | {$baseUrl}/.well-known/api-catalog |
        | MCP discovery document | {$baseUrl}/.well-known/fleetq |

        The `agent_auth` block below is also served inside the Authorization
        Server metadata document, which is where automated clients look for it.

        The protected resource advertises `scopes_supported: ["mcp:use"]` and
        `bearer_methods_supported: ["header"]`. Its `authorization_servers`
        entry points at `{$baseUrl}`, whose issuer matches exactly.

        ## Registration

        FleetQ supports **RFC 7591 dynamic client registration**. An agent can
        self-register an OAuth client without human setup, then run the normal
        authorization-code flow with PKCE:

        ```json
        {
          "agent_auth": {
            "skill": "{$baseUrl}/auth.md",
            "register_uri": "{$baseUrl}/oauth/register",
            "methods": [
              {
                "type": "oauth_dynamic_client_registration",
                "spec": "https://www.rfc-editor.org/rfc/rfc7591",
                "register_uri": "{$baseUrl}/oauth/register",
                "authorization_servers": ["{$baseUrl}"],
                "authorization_endpoint": "{$baseUrl}/oauth/authorize",
                "token_endpoint": "{$baseUrl}/oauth/token",
                "revocation_uri": "{$baseUrl}/oauth/revoke",
                "grant_types_supported": ["authorization_code", "refresh_token"],
                "code_challenge_methods_supported": ["S256"],
                "token_endpoint_auth_methods_supported": ["none", "client_secret_post"],
                "scopes_supported": ["mcp:use"],
                "credential_types_supported": ["oauth_access_token", "oauth_refresh_token"]
              }
            ]
          }
        }
        ```

        Registration is rate limited to 20 requests per hour per IP.

        ## Using the credential

        Send the access token as a bearer header on every request:

        ```
        Authorization: Bearer <access_token>
        ```

        A `401` means the token is missing, expired, or lacks the `mcp:use`
        scope. Refresh with the `refresh_token` grant rather than
        re-registering a client.

        ## Human-issued tokens

        Operators who prefer not to use OAuth can mint a long-lived Sanctum API
        token from **Team settings → API tokens** in the FleetQ UI and hand it
        to the agent. Such tokens are scoped to a single team and carry that
        team's role permissions.
        MARKDOWN;

        return response($body, 200, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Robots-Tag' => 'all',
        ]);
    }
}
