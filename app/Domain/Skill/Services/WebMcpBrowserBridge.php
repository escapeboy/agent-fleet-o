<?php

namespace App\Domain\Skill\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Discovers and calls WebMCP tools on target web pages via the browserless
 * service using Chrome DevTools Protocol (CDP).
 *
 * This allows FleetQ agents with browser skills to interact with target
 * websites via structured WebMCP tools instead of screenshot + click.
 *
 * Gated behind config('webmcp.agent_consumption.enabled').
 */
class WebMcpBrowserBridge
{
    /**
     * Discover WebMCP tools registered on the current page.
     *
     * Uses CDP Runtime.evaluate to query navigator.modelContext for tools.
     *
     * @return array<int, array{name: string, description: string, inputSchema: array}>
     */
    public function discoverTools(string $cdpSessionUrl): array
    {
        if (! config('webmcp.agent_consumption.enabled', false)) {
            return [];
        }

        try {
            $timeout = config('webmcp.agent_consumption.discovery_timeout_ms', 3000);

            // Query navigator.modelContext for registered tools via CDP
            $script = <<<'JS'
                (() => {
                    if (!navigator.modelContext) return JSON.stringify([]);
                    const tools = navigator.modelContext.getRegisteredTools?.() ?? [];
                    return JSON.stringify(tools.map(t => ({
                        name: t.name,
                        description: t.description,
                        inputSchema: t.inputSchema ?? {},
                    })));
                })()
            JS;

            $response = Http::timeout($timeout / 1000)
                ->post($cdpSessionUrl, [
                    'cmd' => 'Runtime.evaluate',
                    'params' => [
                        'expression' => $script,
                        'returnByValue' => true,
                        'awaitPromise' => false,
                    ],
                ]);

            if ($response->successful()) {
                $result = $response->json('result.result.value', '[]');

                return json_decode($result, true) ?: [];
            }
        } catch (\Throwable $e) {
            Log::debug('[WebMcpBrowserBridge] Discovery failed', [
                'error' => $e->getMessage(),
                'session' => $cdpSessionUrl,
            ]);
        }

        return [];
    }

    /**
     * Execute a WebMCP tool on the target page.
     *
     * @return array<string, mixed>
     */
    public function executeTool(string $cdpSessionUrl, string $toolName, array $params = []): array
    {
        if (! config('webmcp.agent_consumption.enabled', false)) {
            return ['success' => false, 'error' => 'WebMCP agent consumption is disabled.'];
        }

        try {
            // JSON-encode both values to prevent JS injection via CDP eval
            $toolNameJson = json_encode($toolName, JSON_THROW_ON_ERROR);
            $paramsJson = json_encode($params, JSON_THROW_ON_ERROR);

            $script = <<<JS
                (async () => {
                    if (!navigator.modelContext) return JSON.stringify({ success: false, error: 'No WebMCP support' });
                    try {
                        const toolName = {$toolNameJson};
                        const params = {$paramsJson};
                        const tools = navigator.modelContext.getRegisteredTools?.() ?? [];
                        const tool = tools.find(t => t.name === toolName);
                        if (!tool) return JSON.stringify({ success: false, error: 'Tool not found: ' + toolName });
                        const result = await tool.execute(params);
                        return JSON.stringify({ success: true, content: result });
                    } catch (e) {
                        return JSON.stringify({ success: false, error: e.message });
                    }
                })()
            JS;

            $response = Http::timeout(30)
                ->post($cdpSessionUrl, [
                    'cmd' => 'Runtime.evaluate',
                    'params' => [
                        'expression' => $script,
                        'returnByValue' => true,
                        'awaitPromise' => true,
                    ],
                ]);

            if ($response->successful()) {
                $result = $response->json('result.result.value', '{}');

                return json_decode($result, true) ?: ['success' => false, 'error' => 'Invalid response'];
            }

            return ['success' => false, 'error' => 'CDP request failed: '.$response->status()];
        } catch (\Throwable $e) {
            Log::warning('[WebMcpBrowserBridge] Tool execution failed', [
                'tool' => $toolName,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Check if WebMCP agent consumption is enabled.
     */
    public function isEnabled(): bool
    {
        return (bool) config('webmcp.agent_consumption.enabled', false);
    }
}
