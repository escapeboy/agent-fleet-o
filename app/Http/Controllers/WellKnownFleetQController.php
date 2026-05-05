<?php

namespace App\Http\Controllers;

use App\Mcp\Servers\AgentFleetServer;
use Illuminate\Http\JsonResponse;
use ReflectionClass;

/**
 * Serves the FleetQ discovery document at /.well-known/fleetq.
 *
 * Consumers (OpenCode, Claude Code, Codex, Cursor, and other MCP-compatible
 * clients) hit this endpoint first to learn where to authenticate, which
 * endpoints to call, and what capabilities the server offers. Mirrors the
 * Cloudflare "one URL to configure everything" pattern.
 *
 * Public endpoint, unauthenticated — only exposes non-sensitive metadata.
 * Each block is gated by a `discovery.expose_*` config flag so operators
 * can reduce the public surface.
 */
class WellKnownFleetQController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json($this->buildPayload());
    }

    /**
     * Build the discovery payload.
     *
     * Public so the matching MCP tool (`SystemDiscoveryGetTool`) can return
     * the same shape without going through the HTTP stack.
     *
     * @return array<string, mixed>
     */
    public function buildPayload(): array
    {
        $baseUrl = rtrim(config('app.url'), '/');

        $payload = [
            'product' => 'FleetQ',
            'description' => 'AI Agent Mission Control — MCP + API bootstrap for coding assistants.',
        ];

        if (config('discovery.expose_name', true)) {
            $payload['name'] = config('app.name');
        }

        if (config('discovery.expose_version', true)) {
            $payload['version'] = $this->resolveVersion();
        }

        if (config('discovery.expose_mcp', true)) {
            $payload['mcp'] = [
                'http_endpoint' => $baseUrl.'/mcp',
                'stdio_command' => 'php artisan mcp:start agent-fleet',
                'transport' => ['http-sse', 'stdio'],
            ];
        }

        if (config('discovery.expose_api', true)) {
            $payload['api'] = [
                'base_url' => $baseUrl.'/api/v1',
                'docs_url' => $baseUrl.'/docs/api',
            ];
        }

        if (config('discovery.expose_auth', true)) {
            $payload['auth'] = [
                'scheme' => 'bearer',
                'token_endpoint' => $baseUrl.'/api/v1/auth/token',
                'login_url' => $baseUrl.'/login',
                'token_url' => $baseUrl.'/team#api-tokens',
                'env' => 'FLEETQ_TOKEN',
                'docs' => $baseUrl.'/docs/api',
                'instructions' => 'Create a personal API token at token_url, then set it as the FLEETQ_TOKEN env var or pass as `Authorization: Bearer <token>`.',
                'type' => 'bearer',
            ];
        }

        if (config('discovery.expose_tool_count', true)) {
            $payload['tools'] = [
                'count' => $this->countMcpTools(),
            ];
        }

        $payload['endpoints'] = [
            'mcp' => $baseUrl.'/mcp',
            'api' => $baseUrl.'/api/v1',
            'bootstrap' => $baseUrl.'/api/v1/me/bootstrap',
            'openapi' => $baseUrl.'/docs/api',
        ];

        $payload['capabilities'] = [
            'mcp' => true,
            'mcp_transport' => ['http-sse', 'stdio'],
            'byok' => true,
            'codemode' => true,
        ];

        $payload['docs'] = [
            'mcp' => $baseUrl.'/docs/mcp',
            'api' => $baseUrl.'/docs/api',
        ];

        if (config('discovery.expose_generated_at', true)) {
            $payload['generated_at'] = now()->toIso8601String();
        }

        $payload['version_envelope'] = '1.0';

        return $payload;
    }

    private function resolveVersion(): string
    {
        $composerPath = base_path('composer.json');

        if (! is_file($composerPath)) {
            return 'unknown';
        }

        $raw = file_get_contents($composerPath);

        if ($raw === false) {
            return 'unknown';
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return 'unknown';
        }

        $version = $decoded['version'] ?? null;

        return is_string($version) && $version !== '' ? $version : 'unknown';
    }

    private function countMcpTools(): int
    {
        try {
            $reflection = new ReflectionClass(AgentFleetServer::class);
            $defaults = $reflection->getDefaultProperties();
            $tools = $defaults['tools'] ?? [];

            return is_array($tools) ? count($tools) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }
}
