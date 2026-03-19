<?php

namespace App\Domain\Tool\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MCP HTTP client supporting both Streamable HTTP and SSE transports.
 *
 * MCP Spec transports:
 *  - Streamable HTTP: POST /mcp  (Accept: application/json, text/event-stream)
 *  - SSE (legacy):    GET /sse → event: endpoint → POST <session_url>
 */
class McpHttpClient
{
    private const TIMEOUT = 30;

    private const SSE_CONNECT_TIMEOUT = 10;

    /**
     * Call a tool on a remote MCP server.
     *
     * @param  array<string, string>  $headers  Auth headers (e.g. Authorization)
     * @return string Tool result as string for agent consumption
     */
    public function callTool(string $serverUrl, string $toolName, array $arguments = [], array $headers = []): string
    {
        $serverUrl = rtrim($serverUrl, '/');

        // Try Streamable HTTP transport first (MCP spec ≥ 2024-11-05)
        try {
            return $this->callStreamableHttp($serverUrl, $toolName, $arguments, $headers);
        } catch (\Throwable $e) {
            Log::debug('McpHttpClient: Streamable HTTP failed, trying SSE transport', [
                'url' => $serverUrl,
                'error' => $e->getMessage(),
            ]);
        }

        // Fall back to SSE transport (older MCP servers, Playwright MCP)
        return $this->callSseTransport($serverUrl, $toolName, $arguments, $headers);
    }

    /**
     * List all tools exposed by a remote MCP server.
     *
     * @param  array<string, string>  $headers
     * @return array<int, array<string, mixed>> Array of tool definitions
     */
    public function listTools(string $serverUrl, array $headers = []): array
    {
        $serverUrl = rtrim($serverUrl, '/');

        try {
            return $this->listToolsStreamableHttp($serverUrl, $headers);
        } catch (\Throwable $e) {
            Log::debug('McpHttpClient: listTools via Streamable HTTP failed, trying SSE', [
                'url' => $serverUrl,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->listToolsSseTransport($serverUrl, $headers);
    }

    // -------------------------------------------------------------------------
    // Streamable HTTP transport
    // -------------------------------------------------------------------------

    /**
     * Send MCP initialize handshake and return the session ID (if any).
     * Required by MCP spec before any other method. Some servers (e.g. Playwright MCP)
     * silently drop requests that arrive before initialization.
     *
     * @param  array<string, string>  $headers
     */
    private function initializeStreamableHttp(string $url, array $headers): ?string
    {
        $response = Http::withHeaders(array_merge([
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
            'Connection' => 'close',
        ], $headers))
            ->timeout(self::TIMEOUT)
            ->post("{$url}/mcp", [
                'jsonrpc' => '2.0',
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => new \stdClass,
                    'clientInfo' => ['name' => 'FleetQ', 'version' => '1.0'],
                ],
                'id' => 1,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException("MCP initialize HTTP {$response->status()}: {$response->body()}");
        }

        // Some servers issue a session token for subsequent requests
        return $response->header('Mcp-Session-Id') ?: null;
    }

    private function callStreamableHttp(string $url, string $toolName, array $arguments, array $headers): string
    {
        $sessionId = $this->initializeStreamableHttp($url, $headers);

        $requestHeaders = array_merge([
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
            'Connection' => 'close',
        ], $headers);

        if ($sessionId) {
            $requestHeaders['Mcp-Session-Id'] = $sessionId;
        }

        $response = Http::withHeaders($requestHeaders)
            ->timeout(self::TIMEOUT)
            ->post("{$url}/mcp", [
                'jsonrpc' => '2.0',
                'method' => 'tools/call',
                'params' => ['name' => $toolName, 'arguments' => $arguments ?: new \stdClass],
                'id' => 2,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException("MCP HTTP {$response->status()}: {$response->body()}");
        }

        return $this->extractToolResult($this->parseMcpBody($response->body()));
    }

    private function listToolsStreamableHttp(string $url, array $headers): array
    {
        $sessionId = $this->initializeStreamableHttp($url, $headers);

        $requestHeaders = array_merge([
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
            'Connection' => 'close',
        ], $headers);

        if ($sessionId) {
            $requestHeaders['Mcp-Session-Id'] = $sessionId;
        }

        $response = Http::withHeaders($requestHeaders)
            ->timeout(self::TIMEOUT)
            ->post("{$url}/mcp", [
                'jsonrpc' => '2.0',
                'method' => 'tools/list',
                'params' => new \stdClass,
                'id' => 2,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException("MCP tools/list HTTP {$response->status()}");
        }

        $data = $this->parseMcpBody($response->body());

        return $data['result']['tools'] ?? [];
    }

    /**
     * Parse MCP response body — handles both plain JSON and SSE event streams.
     * Playwright MCP returns responses as SSE even for single-value results.
     *
     * @return array<string, mixed>
     */
    private function parseMcpBody(string $body): array
    {
        $body = trim($body);

        // SSE stream: look for "data: {...}" lines
        if (str_contains($body, 'data:')) {
            foreach (explode("\n", $body) as $line) {
                $line = trim($line);
                if (str_starts_with($line, 'data:')) {
                    $decoded = json_decode(trim(substr($line, 5)), true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            }

            return [];
        }

        return json_decode($body, true) ?? [];
    }

    // -------------------------------------------------------------------------
    // SSE transport (legacy — used by Playwright MCP)
    // -------------------------------------------------------------------------

    private function callSseTransport(string $url, string $toolName, array $arguments, array $headers): string
    {
        $sessionUrl = $this->initSseSession($url, $headers);

        $response = Http::withHeaders(array_merge([
            'Content-Type' => 'application/json',
        ], $headers))
            ->timeout(self::TIMEOUT)
            ->post($sessionUrl, [
                'jsonrpc' => '2.0',
                'method' => 'tools/call',
                'params' => ['name' => $toolName, 'arguments' => $arguments ?: new \stdClass],
                'id' => 1,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException("MCP SSE tool call failed: HTTP {$response->status()}");
        }

        $body = $response->json();
        if (isset($body['result'])) {
            return $this->extractToolResult($body);
        }

        // Some SSE servers return the response on the stream, not inline in the POST.
        // For Playwright MCP, the POST /message returns the result inline.
        throw new \RuntimeException('MCP SSE: result not in POST response; async SSE polling not supported.');
    }

    private function listToolsSseTransport(string $url, array $headers): array
    {
        $sessionUrl = $this->initSseSession($url, $headers);

        $response = Http::withHeaders(array_merge([
            'Content-Type' => 'application/json',
        ], $headers))
            ->timeout(self::TIMEOUT)
            ->post($sessionUrl, [
                'jsonrpc' => '2.0',
                'method' => 'tools/list',
                'params' => new \stdClass,
                'id' => 1,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException("MCP SSE tools/list failed: HTTP {$response->status()}");
        }

        return $response->json('result.tools', []);
    }

    /**
     * Connect to SSE endpoint, read the first 'endpoint' event, return absolute session URL.
     */
    private function initSseSession(string $serverUrl, array $headers): string
    {
        $response = Http::withHeaders(array_merge(['Accept' => 'text/event-stream'], $headers))
            ->timeout(self::SSE_CONNECT_TIMEOUT)
            ->withOptions(['stream' => true])
            ->get("{$serverUrl}/sse");

        if (! $response->successful()) {
            throw new \RuntimeException("MCP SSE connect failed: HTTP {$response->status()}");
        }

        $sessionPath = null;

        foreach (explode("\n", $response->body()) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'data:')) {
                $sessionPath = trim(substr($line, 5));
                break;
            }
        }

        if (! $sessionPath) {
            throw new \RuntimeException('MCP SSE: no endpoint event received');
        }

        // Build absolute session URL from base server URL + session path
        $parsed = parse_url($serverUrl);
        $base = "{$parsed['scheme']}://{$parsed['host']}";
        if (isset($parsed['port'])) {
            $base .= ":{$parsed['port']}";
        }

        return $base.$sessionPath;
    }

    // -------------------------------------------------------------------------
    // Result extraction
    // -------------------------------------------------------------------------

    private function extractToolResult(array $response): string
    {
        if (isset($response['error'])) {
            $msg = $response['error']['message'] ?? json_encode($response['error']);

            return "MCP error: {$msg}";
        }

        $content = $response['result']['content'] ?? [];

        if (empty($content)) {
            return '(no output)';
        }

        $parts = [];
        foreach ($content as $item) {
            $type = $item['type'] ?? 'text';
            if ($type === 'text') {
                $parts[] = $item['text'] ?? '';
            } elseif ($type === 'image') {
                $parts[] = "[image: {$item['mimeType']}]";
            } elseif ($type === 'resource') {
                $parts[] = $item['resource']['text'] ?? $item['resource']['uri'] ?? '[resource]';
            }
        }

        return implode("\n", $parts);
    }
}
