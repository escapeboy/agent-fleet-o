<?php

namespace App\Domain\Tool\Services;

use App\Domain\Bridge\Services\BridgeRouter;
use App\Domain\Tool\Models\Tool;
use App\Infrastructure\Bridge\BridgeRequestRegistry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * MCP bridge client — proxies tool calls through the relay to a remote bridge daemon.
 *
 * Flow: Laravel → Redis queue → Relay binary → WebSocket → Bridge Daemon → MCP Server (stdio) → result
 *       Laravel ← Redis stream ← Relay binary ← WebSocket ← Bridge Daemon ←
 *
 * Uses the same Redis frame-based protocol as LocalBridgeGateway:
 *   - RPUSH to bridge:req:{teamId} with frame_type 0x0020 (FrameMcpToolCall)
 *   - BLPOP on bridge:stream:{requestId} for the response
 *
 * The relay binary is a frame-agnostic tunnel — it forwards payloads between
 * Redis and the bridge daemon WebSocket without interpreting frame_type.
 *
 * Each team has its own bridge — tool calls are routed via team_id.
 */
class McpBridgeClient
{
    /** Frame type for MCP tool calls (tools/call, tools/list). */
    private const FRAME_MCP_TOOL_CALL = 0x0020;

    /** Frame type for MCP tool responses. */
    private const FRAME_MCP_TOOL_RESULT = 0x0021;

    /** Frame type for errors. */
    private const FRAME_ERROR = 0x00FF;

    /** Default timeout for MCP tool calls (seconds). */
    private const DEFAULT_TIMEOUT = 60;

    public function __construct(
        private readonly BridgeRouter $bridgeRouter,
        private readonly BridgeRequestRegistry $registry,
    ) {}

    /**
     * Call a tool on a bridge-hosted MCP server.
     *
     * @param  array<string, mixed>  $arguments
     * @return string Tool result text
     *
     * @throws \RuntimeException if no bridge is connected or call times out
     */
    public function callTool(Tool $tool, string $toolName, array $arguments = []): string
    {
        $teamId = $tool->team_id;
        $serverName = $tool->transport_config['bridge_server_name'] ?? $tool->name;
        $timeout = $tool->settings['timeout'] ?? self::DEFAULT_TIMEOUT;

        $bridge = $this->bridgeRouter->resolveForMcpServer($teamId, $serverName);

        if (! $bridge) {
            throw new \RuntimeException(
                "McpBridgeClient: no active bridge with MCP server '{$serverName}' for team {$teamId}. "
                .'Ensure the bridge daemon is running and the MCP server is configured.',
            );
        }

        $requestId = Str::uuid()->toString();

        Log::info('McpBridgeClient: calling tool via bridge', [
            'request_id' => $requestId,
            'team_id' => $teamId,
            'bridge_id' => $bridge->id,
            'server' => $serverName,
            'tool' => $toolName,
            'timeout' => $timeout,
        ]);

        // Register in-flight request so the relay can push the response
        $this->registry->register($requestId, $teamId);

        // Push MCP request to the Redis queue — relay binary reads via BLPOP
        Redis::connection('bridge')->rpush(
            "bridge:req:{$teamId}",
            json_encode([
                'request_id' => $requestId,
                'frame_type' => self::FRAME_MCP_TOOL_CALL,
                'payload' => [
                    'request_id' => $requestId,
                    'server' => $serverName,
                    'method' => 'tools/call',
                    'params' => [
                        'name' => $toolName,
                        'arguments' => ! empty($arguments) ? $arguments : new \stdClass,
                    ],
                    'timeout' => $timeout,
                ],
            ]),
        );

        // Wait for the response via BLPOP on the Redis stream
        $item = $this->registry->popChunk($requestId, $timeout + 10);

        if ($item === null) {
            Log::error('McpBridgeClient: timeout waiting for MCP response', [
                'request_id' => $requestId,
                'tool' => $toolName,
                'timeout' => $timeout,
            ]);

            throw new \RuntimeException(
                "McpBridgeClient: timeout waiting for '{$toolName}' response after {$timeout}s",
            );
        }

        // Check for error sentinel stored by the relay
        $usage = $this->registry->getUsage($requestId);
        if (isset($usage['__error'])) {
            throw new \RuntimeException("McpBridgeClient: MCP error for '{$toolName}': {$usage['__error']}");
        }

        $chunk = $item['chunk'] ?? '';

        if ($chunk === '' && ($item['done'] ?? false)) {
            return '(no output)';
        }

        return $chunk;
    }

    /**
     * Discover tools from a bridge-hosted MCP server.
     *
     * @return array<int, array<string, mixed>>
     */
    public function discover(Tool $tool): array
    {
        $teamId = $tool->team_id;
        $serverName = $tool->transport_config['bridge_server_name'] ?? $tool->name;

        $bridge = $this->bridgeRouter->resolveForMcpServer($teamId, $serverName);

        if (! $bridge) {
            throw new \RuntimeException(
                "McpBridgeClient: no active bridge with MCP server '{$serverName}' for team {$teamId}.",
            );
        }

        $requestId = Str::uuid()->toString();

        $this->registry->register($requestId, $teamId);

        Redis::connection('bridge')->rpush(
            "bridge:req:{$teamId}",
            json_encode([
                'request_id' => $requestId,
                'frame_type' => self::FRAME_MCP_TOOL_CALL,
                'payload' => [
                    'request_id' => $requestId,
                    'server' => $serverName,
                    'method' => 'tools/list',
                    'params' => new \stdClass,
                    'timeout' => 15,
                ],
            ]),
        );

        $item = $this->registry->popChunk($requestId, 30);

        if ($item === null) {
            throw new \RuntimeException("McpBridgeClient: timeout discovering tools on '{$serverName}'.");
        }

        $usage = $this->registry->getUsage($requestId);
        if (isset($usage['__error'])) {
            throw new \RuntimeException("McpBridgeClient: error discovering tools: {$usage['__error']}");
        }

        // The bridge daemon sends the tools list as JSON in the chunk
        $result = json_decode($item['chunk'] ?? '', true);

        return $result['tools'] ?? $result ?? [];
    }
}
