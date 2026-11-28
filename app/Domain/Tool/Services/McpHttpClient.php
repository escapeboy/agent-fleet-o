<?php

namespace App\Domain\Tool\Services;

use App\Support\McpEndpointUrl;
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

    private const CONNECT_TIMEOUT = 15;

    private const SSE_CONNECT_TIMEOUT = 10;

    /** HTTP statuses on initialize that mean the server does not speak Streamable HTTP. */
    private const SSE_FALLBACK_STATUSES = [404, 405, 415];

    /** Exception code for a 2xx initialize reply that is not JSON-RPC. */
    private const NOT_MCP_CODE = 1;

    private const ERROR_BODY_LIMIT = 300;

    /** The last exception thrown by initializeStreamableHttp(); only these may trigger the SSE fallback. */
    private ?\RuntimeException $initializeFailure = null;

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
            if (! $this->shouldFallBackToSse($e)) {
                throw new \RuntimeException('MCP Streamable HTTP: '.$e->getMessage(), 0, $e);
            }
            Log::debug('McpHttpClient: Streamable HTTP failed, trying SSE transport', [
                'url' => $serverUrl,
                'error' => $e->getMessage(),
            ]);
            $streamableError = $e;
        }

        // Fall back to SSE transport (older MCP servers, Playwright MCP)
        try {
            return $this->callSseTransport($serverUrl, $toolName, $arguments, $headers);
        } catch (\Throwable $sseError) {
            throw $this->bothTransportsFailed($streamableError, $sseError);
        }
    }

    /**
     * List all tools exposed by a remote MCP server.
     *
     * @param  array<string, string>  $headers
     * @param  float|null  $timeoutSeconds  Total budget for the whole listing, both transports included (null = per-request defaults)
     * @return array<int, array<string, mixed>> Array of tool definitions
     */
    public function listTools(string $serverUrl, array $headers = [], ?float $timeoutSeconds = null): array
    {
        $serverUrl = rtrim($serverUrl, '/');
        $deadline = $timeoutSeconds !== null ? microtime(true) + $timeoutSeconds : null;

        try {
            return $this->listToolsStreamableHttp($serverUrl, $headers, $deadline);
        } catch (\Throwable $e) {
            if (! $this->shouldFallBackToSse($e)) {
                throw new \RuntimeException('MCP Streamable HTTP: '.$e->getMessage(), 0, $e);
            }
            Log::debug('McpHttpClient: listTools via Streamable HTTP failed, trying SSE', [
                'url' => $serverUrl,
                'error' => $e->getMessage(),
            ]);
            $streamableError = $e;
        }

        try {
            return $this->listToolsSseTransport($serverUrl, $headers, $deadline);
        } catch (\Throwable $sseError) {
            throw $this->bothTransportsFailed($streamableError, $sseError);
        }
    }

    /**
     * Only fall back to SSE when initialize showed the server does not speak Streamable HTTP:
     * a 404/405/415 status, or a 2xx reply that is not JSON-RPC. Anything else (auth errors,
     * 5xx, timeouts, failures after a good initialize) is the real cause and is reported as is.
     */
    private function shouldFallBackToSse(\Throwable $e): bool
    {
        $isInitializeFailure = $this->initializeFailure !== null && $e === $this->initializeFailure;
        $this->initializeFailure = null;

        if (! $isInitializeFailure) {
            return false;
        }

        return in_array($e->getCode(), self::SSE_FALLBACK_STATUSES, true) || $e->getCode() === self::NOT_MCP_CODE;
    }

    private function bothTransportsFailed(\Throwable $streamableError, \Throwable $sseError): \RuntimeException
    {
        return new \RuntimeException(
            'MCP server failed on both transports. Streamable HTTP: '.$streamableError->getMessage()
            .'; SSE fallback: '.$sseError->getMessage(),
            0,
            $sseError,
        );
    }

    private function truncateBody(string $body): string
    {
        return mb_strlen($body) > self::ERROR_BODY_LIMIT
            ? mb_substr($body, 0, self::ERROR_BODY_LIMIT).'…'
            : $body;
    }

    /**
     * Seconds a request may take: the default, capped by what is left before the deadline.
     */
    private function budget(?float $deadline, float $default): float
    {
        if ($deadline === null) {
            return $default;
        }

        $left = $deadline - microtime(true);
        if ($left <= 0) {
            throw new \RuntimeException('McpHttpClient: listing deadline exceeded');
        }

        return min($default, $left);
    }

    // -------------------------------------------------------------------------
    // Streamable HTTP transport
    // -------------------------------------------------------------------------

    /**
     * Send MCP initialize handshake and return the session ID (if any).
     * Required by MCP spec before any other method. Some servers (e.g. Playwright MCP)
     * silently drop requests that arrive before initialization.
     *
     * A 2xx reply with a JSON-RPC 'error' still speaks MCP; a 2xx reply without any
     * JSON-RPC keys does not, and is reported as an initialize failure (SSE fallback).
     *
     * @param  array<string, string>  $headers
     */
    private function initializeStreamableHttp(string $url, array $headers, ?float $deadline = null): ?string
    {
        $response = Http::withHeaders(array_merge([
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
            'Connection' => 'close',
        ], $headers))
            ->timeout($this->budget($deadline, self::TIMEOUT))
            ->connectTimeout($this->budget($deadline, self::CONNECT_TIMEOUT))
            ->post(McpEndpointUrl::normalize($url), [
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
            throw $this->initializeFailed(
                "MCP initialize HTTP {$response->status()}: {$this->truncateBody($response->body())}",
                $response->status(),
            );
        }

        $data = $this->parseMcpBody($response->body());
        if (! array_key_exists('jsonrpc', $data) && ! array_key_exists('result', $data) && ! array_key_exists('error', $data)) {
            $contentType = $response->header('Content-Type') ?: 'none';

            throw $this->initializeFailed(
                "MCP initialize: reply is not JSON-RPC (Content-Type: {$contentType})",
                self::NOT_MCP_CODE,
            );
        }

        // Some servers issue a session token for subsequent requests
        return $response->header('Mcp-Session-Id') ?: null;
    }

    private function initializeFailed(string $message, int $code): \RuntimeException
    {
        return $this->initializeFailure = new \RuntimeException($message, $code);
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
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->post(McpEndpointUrl::normalize($url), [
                'jsonrpc' => '2.0',
                'method' => 'tools/call',
                'params' => ['name' => $toolName, 'arguments' => $arguments ?: new \stdClass],
                'id' => 2,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException("MCP HTTP {$response->status()}: {$this->truncateBody($response->body())}");
        }

        return $this->extractToolResult($this->parseMcpBody($response->body()));
    }

    private function listToolsStreamableHttp(string $url, array $headers, ?float $deadline = null): array
    {
        $sessionId = $this->initializeStreamableHttp($url, $headers, $deadline);

        $requestHeaders = array_merge([
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
            'Connection' => 'close',
        ], $headers);

        if ($sessionId) {
            $requestHeaders['Mcp-Session-Id'] = $sessionId;
        }

        $response = Http::withHeaders($requestHeaders)
            ->timeout($this->budget($deadline, self::TIMEOUT))
            ->connectTimeout($this->budget($deadline, self::CONNECT_TIMEOUT))
            ->post(McpEndpointUrl::normalize($url), [
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

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
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

    private function listToolsSseTransport(string $url, array $headers, ?float $deadline = null): array
    {
        $sessionUrl = $this->initSseSession($url, $headers, $deadline);

        $response = Http::withHeaders(array_merge([
            'Content-Type' => 'application/json',
        ], $headers))
            ->timeout($this->budget($deadline, self::TIMEOUT))
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
    private function initSseSession(string $serverUrl, array $headers, ?float $deadline = null): string
    {
        $sseUrl = McpEndpointUrl::withPathSegment($serverUrl, '/sse');
        $response = Http::withHeaders(array_merge(['Accept' => 'text/event-stream'], $headers))
            ->timeout($this->budget($deadline, self::SSE_CONNECT_TIMEOUT))
            ->withOptions(['stream' => true])
            ->get($sseUrl);

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

        return $this->resolveSessionUrl($serverUrl, $sseUrl, $sessionPath);
    }

    /**
     * Resolve the SSE `endpoint` event against the SSE URL: absolute URLs are used as is,
     * `/path` is joined to the origin, anything else is relative to the SSE URL's directory.
     * The tool URL's query (e.g. an API key) is added before any fragment, skipping params
     * the endpoint already has, and only when the result is on the same host.
     */
    private function resolveSessionUrl(string $serverUrl, string $sseUrl, string $endpoint): string
    {
        $sse = parse_url($sseUrl);
        $origin = "{$sse['scheme']}://{$sse['host']}".(isset($sse['port']) ? ":{$sse['port']}" : '');

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $endpoint)) {
            $resolved = $endpoint;
        } elseif (str_starts_with($endpoint, '//')) {
            $resolved = "{$sse['scheme']}:{$endpoint}";
        } elseif (str_starts_with($endpoint, '/')) {
            $resolved = $origin.$endpoint;
        } else {
            $dir = substr((string) ($sse['path'] ?? '/'), 0, (int) strrpos((string) ($sse['path'] ?? '/'), '/') + 1);
            $resolved = $origin.$dir.$endpoint;
        }

        $toolQuery = (string) (parse_url($serverUrl, PHP_URL_QUERY) ?? '');
        if ($toolQuery === '' || parse_url($resolved, PHP_URL_HOST) !== $sse['host']) {
            return $resolved;
        }

        $fragment = '';
        if (($hash = strpos($resolved, '#')) !== false) {
            $fragment = substr($resolved, $hash);
            $resolved = substr($resolved, 0, $hash);
        }
        $existing = [];
        parse_str((string) (parse_url($resolved, PHP_URL_QUERY) ?? ''), $existing);

        $extra = array_filter(
            explode('&', $toolQuery),
            fn (string $pair) => $pair !== '' && ! array_key_exists(urldecode(explode('=', $pair, 2)[0]), $existing),
        );

        if ($extra) {
            $resolved .= (str_contains($resolved, '?') ? '&' : '?').implode('&', $extra);
        }

        return $resolved.$fragment;
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
