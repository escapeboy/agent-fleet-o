<?php

namespace App\Domain\Tool\Actions;

use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Models\McpServerRegistry;
use App\Domain\Tool\Models\Tool;
use Illuminate\Support\Str;
use RuntimeException;

class InstallFromRegistryAction
{
    /**
     * Materialize a registry entry as a Tool row owned by the given team.
     * Idempotent: if the team already has a Tool from this registry entry,
     * the existing row is returned unchanged.
     */
    public function execute(McpServerRegistry $entry, string $teamId): Tool
    {
        if (! $entry->is_active) {
            throw new RuntimeException('Registry entry is not active.');
        }

        $existing = Tool::query()
            ->where('team_id', $teamId)
            ->where('registry_server_id', $entry->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $slug = $this->resolveSlug($teamId, $entry);

        return Tool::create([
            'team_id' => $teamId,
            'registry_server_id' => $entry->id,
            'name' => $entry->name,
            'slug' => $slug,
            'description' => $entry->description,
            'type' => $entry->transport,
            'status' => ToolStatus::Active->value,
            'transport_config' => $entry->connection,
            'credentials' => [],
            'tool_definitions' => [],
            'settings' => [
                'tool_allowlist' => $entry->tool_allowlist,
                'installed_from_registry' => true,
                'registry_trust_level' => $entry->trust_level?->value,
            ],
        ]);
    }

    private function resolveSlug(string $teamId, McpServerRegistry $entry): string
    {
        $base = 'registry-'.$entry->slug;
        $slug = $base;
        $suffix = 1;

        while (Tool::query()->where('team_id', $teamId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return Str::limit($slug, 100, '');
    }
}
