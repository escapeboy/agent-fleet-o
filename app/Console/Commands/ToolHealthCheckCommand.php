<?php

namespace App\Console\Commands;

use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\McpHttpClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ToolHealthCheckCommand extends Command
{
    protected $signature = 'tools:health-check';

    protected $description = 'Check health of active MCP HTTP tools and refresh tool definitions if changed';

    public function handle(McpHttpClient $client): int
    {
        // Only check HTTP tools — stdio tools require a running local process
        // and cannot be health-checked without side effects.
        $count = 0;
        $healthy = 0;
        $unreachable = 0;
        $refreshed = 0;

        Tool::withoutGlobalScopes()
            ->where('status', ToolStatus::Active)
            ->where('type', ToolType::McpHttp)
            ->chunk(50, function ($tools) use ($client, &$count, &$healthy, &$unreachable, &$refreshed) {
                foreach ($tools as $tool) {
                    $count++;

                    try {
                        $serverUrl = $tool->transport_config['url'] ?? null;
                        if (! $serverUrl) {
                            continue;
                        }

                        $headers = $this->buildHeaders($tool);
                        $toolDefs = $client->listTools($serverUrl, $headers);

                        $updates = [
                            'health_status' => 'healthy',
                            'last_health_check' => now(),
                        ];

                        // Normalize for stable comparison
                        if (! $this->defsMatch($toolDefs, $tool->tool_definitions)) {
                            $updates['tool_definitions'] = $toolDefs;
                            $refreshed++;
                            $this->line("  Refreshed definitions for: {$tool->name}");
                        }

                        $tool->update($updates);
                        $healthy++;
                    } catch (\Throwable $e) {
                        $tool->update([
                            'health_status' => 'unreachable',
                            'last_health_check' => now(),
                        ]);
                        $unreachable++;

                        Log::debug('ToolHealthCheck: unreachable', [
                            'tool_id' => $tool->id,
                            'tool_name' => $tool->name,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $this->info("Health check complete: {$count} tools checked, {$healthy} healthy, {$unreachable} unreachable, {$refreshed} refreshed.");

        return self::SUCCESS;
    }

    /**
     * Build auth headers from tool credentials/transport_config.
     *
     * @return array<string, string>
     */
    private function buildHeaders(Tool $tool): array
    {
        $headers = [];
        $config = $tool->transport_config ?? [];

        if (! empty($config['headers'])) {
            $headers = $config['headers'];
        }

        // Add bearer token if present in credentials
        $creds = $tool->credentials ?? [];
        if (! empty($creds['api_key'])) {
            $headers['Authorization'] = 'Bearer '.$creds['api_key'];
        } elseif (! empty($creds['token'])) {
            $headers['Authorization'] = 'Bearer '.$creds['token'];
        }

        return $headers;
    }

    /**
     * Compare tool definitions using normalized JSON to avoid false updates
     * from key ordering differences.
     */
    private function defsMatch(?array $newDefs, ?array $oldDefs): bool
    {
        if ($newDefs === null && $oldDefs === null) {
            return true;
        }

        return json_encode($this->sortRecursive($newDefs ?? []))
            === json_encode($this->sortRecursive($oldDefs ?? []));
    }

    private function sortRecursive(array $array): array
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                $value = $this->sortRecursive($value);
            }
        }

        // Sort by keys if associative, leave indexed arrays in order
        if (array_is_list($array)) {
            return $array;
        }

        ksort($array);

        return $array;
    }
}
