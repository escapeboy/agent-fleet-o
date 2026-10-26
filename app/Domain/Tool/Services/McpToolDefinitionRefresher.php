<?php

namespace App\Domain\Tool\Services;

use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Re-lists an MCP server's tools when an agent execution resolves its tools,
 * so a server whose tool set changed is seen by the next run instead of only
 * after the hourly tools:refresh-definitions pass.
 *
 * Why not notifications/tools/list_changed: McpHttpClient and McpStdioClient
 * open a fresh session/process per request and close it after the response,
 * and the stdio reader drops every id-less message. There is no live
 * connection a list_changed notification could arrive on.
 *
 * Changes go through ToolDefinitionPinner, so definition pinning still holds
 * an unapproved change. A failed listing keeps the stored definitions.
 *
 * Cost bound: at most one listing attempt per tool per min_interval window,
 * and each attempt has its own short timeout. The window is a cache key, not
 * last_health_check: tools:health-check also writes that column without
 * listing anything, and a failed attempt never writes it, so a hanging server
 * would be retried on every run. Cache::add is atomic, so concurrent runs of
 * the same tool do not all list at once.
 */
class McpToolDefinitionRefresher
{
    public function __construct(
        private readonly McpHttpClient $httpClient,
        private readonly McpStdioClient $stdioClient,
        private readonly ToolDefinitionPinner $pinner,
    ) {}

    public static function supports(Tool $tool): bool
    {
        return in_array($tool->type, [ToolType::McpHttp, ToolType::McpStdio], true);
    }

    /**
     * @param  Tool  $source  Tool to list from (may be a clone with credentials injected)
     * @param  Tool  $target  Persisted tool row that receives the definitions
     */
    public function refresh(Tool $source, Tool $target): void
    {
        if (! (bool) config('tools.refresh_definitions_on_execution', true) || ! self::supports($source)) {
            return;
        }

        // The window starts at the attempt, not at success: a server that fails
        // or hangs costs one timeout per window, not one per run.
        $interval = (int) config('tools.refresh_definitions_min_interval', 300);

        $timeout = max(1, (int) config('tools.refresh_definitions_timeout', 5));

        try {
            // A cache outage skips the refresh rather than listing anyway: without
            // the window a hanging server would cost a timeout on every run. The
            // stored definitions stay, and tools:refresh-definitions still runs.
            if ($interval > 0 && ! Cache::add(self::windowKey($target), now()->getTimestamp(), $interval)) {
                return;
            }

            $definitions = match ($source->type) {
                ToolType::McpHttp => $this->listHttpTools($source, $timeout),
                ToolType::McpStdio => $this->stdioClient->discover($source, $timeout),
            };
        } catch (\Throwable $e) {
            Log::warning('MCP tool definitions refresh failed; using stored definitions', [
                'tool_id' => $target->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        // An empty list is more likely a broken server than a server with no
        // tools; keep what we had rather than strip the agent's tools.
        if ($definitions === []) {
            return;
        }

        $this->pinner->sync($target, $definitions);
        $target->update(['health_status' => 'healthy', 'last_health_check' => now()]);
    }

    public static function windowKey(Tool $tool): string
    {
        return "mcp_definitions_refreshed:{$tool->id}";
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listHttpTools(Tool $tool, int $timeout): array
    {
        $config = $tool->transport_config ?? [];
        $url = $config['url'] ?? null;

        if (! $url) {
            return [];
        }

        $headers = [];
        if ($authHeader = ($config['headers']['Authorization'] ?? $config['auth_header'] ?? null)) {
            $headers['Authorization'] = $authHeader;
        }

        return $this->httpClient->listTools($url, $headers, (float) $timeout);
    }
}
