<?php

namespace Database\Seeders;

use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Models\Tool;

/**
 * Seeds platform tools (team_id = null, is_platform = true).
 *
 * Platform tools exist once for all teams. Each team activates them
 * individually and provides their own credentials via team_tool_activations.
 *
 * Uses the same tool definitions as PopularToolsSeeder (which remains
 * for the community edition single-team setup).
 */
class PlatformToolsSeeder extends PopularToolsSeeder
{
    public function run(): void
    {
        $definitions = $this->toolDefinitions();
        $created = 0;
        $skipped = 0;

        foreach ($definitions as $def) {
            $tool = Tool::withoutGlobalScopes()->updateOrCreate(
                ['team_id' => null, 'slug' => $def['slug']],
                [
                    'is_platform' => true,
                    'name' => $def['name'],
                    'description' => $def['description'],
                    'type' => $def['type'],
                    'status' => ToolStatus::Active,
                    'risk_level' => $def['risk_level'],
                    'transport_config' => $def['transport_config'],
                    'tool_definitions' => $def['tool_definitions'],
                    'settings' => $def['settings'],
                ],
            );

            if ($tool->wasRecentlyCreated) {
                $created++;
            } else {
                $skipped++;
            }
        }

        $this->command?->info("Platform tools: {$created} created, {$skipped} already existed.");
    }
}
