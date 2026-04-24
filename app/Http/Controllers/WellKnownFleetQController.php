<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Serves the FleetQ discovery document at /.well-known/fleetq.
 *
 * Consumers (OpenCode, Claude Code, Codex, and other MCP-compatible clients)
 * hit this endpoint first to learn where to authenticate, which endpoints to
 * call, and what capabilities the server offers. Mirrors the Cloudflare
 * "one URL to configure everything" pattern.
 *
 * Public endpoint, unauthenticated — must only expose non-sensitive metadata.
 */
class WellKnownFleetQController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $baseUrl = rtrim(config('app.url'), '/');

        return response()->json([
            'version' => '1.0',
            'product' => 'FleetQ',
            'description' => 'AI Agent Mission Control — MCP + API bootstrap for coding assistants.',
            'auth' => [
                'type' => 'bearer',
                'login_url' => $baseUrl.'/login',
                'token_url' => $baseUrl.'/team#api-tokens',
                'env' => 'FLEETQ_TOKEN',
                'docs' => $baseUrl.'/docs/api',
                'instructions' => 'Create a personal API token at token_url, then set it as the FLEETQ_TOKEN env var or pass as `Authorization: Bearer <token>`.',
            ],
            'endpoints' => [
                'mcp' => $baseUrl.'/mcp',
                'api' => $baseUrl.'/api/v1',
                'bootstrap' => $baseUrl.'/api/v1/me/bootstrap',
                'openapi' => $baseUrl.'/docs/api',
            ],
            'capabilities' => [
                'mcp' => true,
                'mcp_transport' => ['http-sse', 'stdio'],
                'byok' => true,
                'codemode' => true,
            ],
            'docs' => [
                'mcp' => $baseUrl.'/docs/mcp',
                'api' => $baseUrl.'/docs/api',
            ],
        ]);
    }
}
