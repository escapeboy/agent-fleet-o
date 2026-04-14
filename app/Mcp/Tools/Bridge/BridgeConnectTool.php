<?php

namespace App\Mcp\Tools\Bridge;

use App\Domain\Bridge\Models\BridgeConnection;
use App\Domain\Bridge\Models\BridgeConnectionStatus;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class BridgeConnectTool extends Tool
{
    protected string $name = 'bridge_connect';

    protected string $description = <<<'DESC'
Connect a FleetQ Bridge via HTTP tunnel URL (HTTP mode).

Use when the bridge daemon is exposed through a tunnel provider such as Cloudflare Tunnel,
Tailscale Funnel, ngrok, or a custom reverse proxy. FleetQ calls /discover on the provided
URL to validate reachability and fetch available agents, LLMs, and MCP servers.

If a connection with the same endpoint_url already exists for the team it is updated in place.
DESC;

    public function schema(JsonSchema $schema): array
    {
        return [
            'endpoint_url' => $schema->string()
                ->description('Public HTTPS URL of the bridge daemon (e.g. https://my-machine.trycloudflare.com)')
                ->required(),
            'tunnel_provider' => $schema->string()
                ->description('Tunnel provider: cloudflare, tailscale, ngrok, or custom (default: custom)')
                ->enum(['cloudflare', 'tailscale', 'ngrok', 'custom']),
            'label' => $schema->string()
                ->description('Human-readable label for this connection (optional)'),
            'endpoint_secret' => $schema->string()
                ->description('Bearer token sent in Authorization header when calling the bridge (optional)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'endpoint_url' => 'required|url|max:500',
            'tunnel_provider' => 'nullable|string|in:cloudflare,tailscale,ngrok,custom',
            'label' => 'nullable|string|max:100',
            'endpoint_secret' => 'nullable|string|max:255',
        ]);

        $teamId = app('mcp.team_id') ?? null;
        $endpointUrl = rtrim($validated['endpoint_url'], '/');

        $headers = [];
        if (! empty($validated['endpoint_secret'])) {
            $headers['Authorization'] = 'Bearer '.$validated['endpoint_secret'];
        }

        try {
            $discoverResponse = Http::timeout(10)
                ->withHeaders($headers)
                ->get($endpointUrl.'/discover');

            if (! $discoverResponse->successful()) {
                return Response::error(
                    "Bridge server responded with HTTP {$discoverResponse->status()}. "
                    .'Ensure your local bridge server is running and the tunnel is active.',
                );
            }

            $discovered = $discoverResponse->json();
        } catch (\Throwable $e) {
            return Response::error('Could not reach the bridge server at the provided URL: '.$e->getMessage());
        }

        $endpoints = [
            'agents' => $discovered['agents'] ?? [],
            'llm_endpoints' => $discovered['llm_endpoints'] ?? [],
            'mcp_servers' => $discovered['mcp_servers'] ?? [],
        ];

        $connection = BridgeConnection::updateOrCreate(
            ['team_id' => $teamId, 'endpoint_url' => $endpointUrl],
            [
                'status' => BridgeConnectionStatus::Connected,
                'endpoint_secret' => $validated['endpoint_secret'] ?? null,
                'tunnel_provider' => $validated['tunnel_provider'] ?? 'custom',
                'label' => $validated['label'] ?? null,
                'endpoints' => $endpoints,
                'connected_at' => now(),
                'last_seen_at' => now(),
            ],
        );

        return Response::text(json_encode([
            'success' => true,
            'id' => $connection->id,
            'endpoint_url' => $connection->endpoint_url,
            'tunnel_provider' => $connection->tunnel_provider,
            'label' => $connection->label,
            'status' => $connection->status->value,
            'agents' => $connection->agents(),
            'llm_endpoints' => $connection->llmEndpoints(),
            'mcp_servers' => $connection->mcpServers(),
            'connected_at' => $connection->connected_at->toISOString(),
        ]));
    }
}
