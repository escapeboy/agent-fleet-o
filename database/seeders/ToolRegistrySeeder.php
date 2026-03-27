<?php

namespace Database\Seeders;

use App\Infrastructure\AI\Services\EmbeddingService;
use App\Mcp\Servers\AgentFleetServer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * Populates tool_registry_entries for semantic tool search.
 *
 * Safe to re-run — uses updateOrCreate on tool_name.
 * Run this after adding new MCP tools or updating descriptions.
 *
 * Usage: php artisan db:seed --class=ToolRegistrySeeder
 */
class ToolRegistrySeeder extends Seeder
{
    public function run(): void
    {
        $toolClasses = $this->resolveToolClasses();

        $hasVector = DB::getDriverName() === 'pgsql'
            && DB::scalar("SELECT COUNT(*) FROM pg_extension WHERE extname = 'vector'") > 0;

        $embeddingService = $hasVector ? app(EmbeddingService::class) : null;

        $this->command->getOutput()->progressStart(count($toolClasses));

        foreach ($toolClasses as $toolClass) {
            try {
                $tool = app($toolClass);
                $name = $tool->name();
                $description = $tool->description();
                $group = $this->extractGroup($toolClass);
                $schema = method_exists($tool, 'toArray') ? ($tool->toArray()['inputSchema'] ?? []) : [];
                $paramNames = array_keys($schema['properties'] ?? []);
                $compositeText = "{$name}: {$description}."
                    .($paramNames ? ' Parameters: '.implode(', ', $paramNames).'.' : '');

                $record = [
                    'id' => Str::orderedUuid()->toString(),
                    'tool_name' => $name,
                    'group' => $group,
                    'description' => $description,
                    'composite_text' => $compositeText,
                    'schema' => json_encode($schema),
                    'updated_at' => now(),
                ];

                if ($hasVector && $embeddingService) {
                    $embedding = $embeddingService->embed($compositeText);
                    $record['embedding'] = $embeddingService->formatForPgvector($embedding);
                }

                DB::table('tool_registry_entries')->upsert(
                    [$record],
                    ['tool_name'],
                    ['group', 'description', 'composite_text', 'schema', 'updated_at'],
                );
            } catch (\Throwable $e) {
                $this->command->warn("Skipped {$toolClass}: {$e->getMessage()}");
            }

            $this->command->getOutput()->progressAdvance();
        }

        $this->command->getOutput()->progressFinish();
        $this->command->info('Tool registry seeded: '.count($toolClasses).' tools processed.');
    }

    /** @return array<int, class-string> */
    private function resolveToolClasses(): array
    {
        $reflection = new ReflectionClass(AgentFleetServer::class);
        $property = $reflection->getProperty('tools');
        $property->setAccessible(true);

        // Instantiate a minimal server instance to read the default tools array
        $server = $reflection->newInstanceWithoutConstructor();

        return $property->getValue($server);
    }

    private function extractGroup(string $toolClass): string
    {
        // e.g. App\Mcp\Tools\Agent\AgentListTool → agent
        $parts = explode('\\', $toolClass);
        $groupPart = $parts[count($parts) - 2] ?? 'other';

        return Str::lower($groupPart);
    }
}
