<?php

namespace App\Domain\Tool\Actions;

use App\Domain\Tool\DTOs\ImportResult;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use Illuminate\Support\Facades\Log;

class ImportMcpServersAction
{
    public function __construct(
        private CreateToolAction $createTool,
    ) {}

    /**
     * Import normalized MCP server configs as Tool records.
     *
     * @param  array  $servers  Array of normalized server configs from McpConfigNormalizer
     */
    public function execute(
        string $teamId,
        array $servers,
        bool $skipExisting = true,
        bool $importDisabled = false,
    ): ImportResult {
        $imported = 0;
        $skipped = 0;
        $failed = 0;
        $details = [];

        foreach ($servers as $server) {
            $name = $server['name'] ?? 'Unknown';
            $slug = $server['slug'] ?? '';

            // Skip disabled servers unless explicitly included
            if (! $importDisabled && ($server['disabled'] ?? false)) {
                $skipped++;
                $details[] = [
                    'name' => $name,
                    'status' => 'skipped',
                    'reason' => 'Disabled in source config',
                    'has_credentials' => false,
                ];

                continue;
            }

            // Check for existing tool with same slug
            if ($skipExisting) {
                $exists = Tool::withoutGlobalScopes()
                    ->where('team_id', $teamId)
                    ->where('slug', $slug)
                    ->exists();

                if ($exists) {
                    $skipped++;
                    $details[] = [
                        'name' => $name,
                        'status' => 'skipped',
                        'reason' => 'Already exists',
                        'has_credentials' => false,
                    ];

                    continue;
                }
            }

            try {
                $type = ToolType::from($server['type']);
                $hasCredentials = ! empty($server['credentials']);

                $tool = $this->createTool->execute(
                    teamId: $teamId,
                    name: $name,
                    type: $type,
                    description: "Imported from {$server['source']}",
                    transportConfig: $server['transport_config'] ?? [],
                    credentials: $server['credentials'] ?? [],
                    settings: [
                        'source_ide' => $server['source'],
                        'imported_at' => now()->toIso8601String(),
                        'warnings' => $server['warnings'] ?? [],
                    ],
                );

                // If server had warnings or was disabled in source, set as disabled
                if (! empty($server['warnings']) || ($server['disabled'] ?? false)) {
                    $tool->update(['status' => ToolStatus::Disabled]);
                }

                $imported++;
                $details[] = [
                    'name' => $name,
                    'status' => 'imported',
                    'reason' => null,
                    'has_credentials' => $hasCredentials,
                ];
            } catch (\Throwable $e) {
                Log::warning('ImportMcpServersAction: failed to import server', [
                    'name' => $name,
                    'error' => $e->getMessage(),
                ]);

                $failed++;
                $details[] = [
                    'name' => $name,
                    'status' => 'failed',
                    'reason' => $e->getMessage(),
                    'has_credentials' => false,
                ];
            }
        }

        return new ImportResult($imported, $skipped, $failed, $details);
    }
}
