<?php

namespace App\Domain\Tool\Services;

use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
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

        try {
            $definitions = match ($source->type) {
                ToolType::McpHttp => $this->listHttpTools($source),
                ToolType::McpStdio => $this->stdioClient->discover($source),
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listHttpTools(Tool $tool): array
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

        return $this->httpClient->listTools($url, $headers);
    }
}
