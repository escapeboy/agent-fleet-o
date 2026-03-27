<?php

namespace App\Console\Commands;

use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\McpHttpClient;
use App\Domain\Tool\Services\McpStdioClient;
use Illuminate\Console\Command;

class RefreshToolDefinitionsCommand extends Command
{
    protected $signature = 'tools:refresh-definitions
                            {--team= : Limit to specific team ID}
                            {--stale-minutes=60 : Only refresh tools not checked within N minutes}';

    protected $description = 'Refresh tool_definitions for active MCP tools by querying their servers';

    public function handle(McpHttpClient $httpClient, McpStdioClient $stdioClient): int
    {
        $staleMinutes = (int) $this->option('stale-minutes');

        $query = Tool::query()
            ->where('status', ToolStatus::Active)
            ->whereIn('type', [ToolType::McpHttp->value, ToolType::McpStdio->value])
            ->where(fn ($q) => $q
                ->whereNull('last_health_check')
                ->orWhere('last_health_check', '<', now()->subMinutes($staleMinutes)),
            );

        if ($teamId = $this->option('team')) {
            $query->where('team_id', $teamId);
        }

        $tools = $query->get();
        $this->info("Refreshing {$tools->count()} tool(s) (stale > {$staleMinutes}min)...");

        $refreshed = 0;
        $failed = 0;

        foreach ($tools as $tool) {
            try {
                $definitions = match ($tool->type) {
                    ToolType::McpHttp => $this->listHttpTools($tool, $httpClient),
                    ToolType::McpStdio => $stdioClient->discover($tool),
                    default => null,
                };

                if ($definitions !== null) {
                    $tool->update([
                        'tool_definitions' => $definitions,
                        'health_status' => 'healthy',
                        'last_health_check' => now(),
                    ]);
                    $this->line('  <info>ok</info> '.$tool->name.' — '.count($definitions).' tool(s)');
                    $refreshed++;
                }
            } catch (\Throwable $e) {
                $tool->update([
                    'health_status' => 'error',
                    'last_health_check' => now(),
                ]);
                $this->line('  <comment>fail</comment> '.$tool->name.': '.$e->getMessage());
                $failed++;
            }
        }

        $this->info("Done: {$refreshed} refreshed, {$failed} failed.");

        return self::SUCCESS;
    }

    /**
     * List tools from an MCP HTTP server, extracting the URL and auth headers from the Tool.
     *
     * @return array<int, array<string, mixed>>
     */
    private function listHttpTools(Tool $tool, McpHttpClient $httpClient): array
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

        return $httpClient->listTools($url, $headers);
    }
}
