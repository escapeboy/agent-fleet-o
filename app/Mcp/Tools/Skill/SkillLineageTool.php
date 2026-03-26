<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Skill\Models\SkillVersion;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Returns the full version lineage and evolution history for a skill,
 * ordered chronologically with parent/child relationship data.
 */
#[IsReadOnly]
#[IsIdempotent]
class SkillLineageTool extends Tool
{
    protected string $name = 'skill_lineage';

    protected string $description = 'Get the version lineage and evolution history for a skill.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'skill_id' => $schema->string()
                ->description('The skill UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['skill_id' => 'required|string']);

        $versions = SkillVersion::query()
            ->where('skill_id', $validated['skill_id'])
            ->orderBy('created_at')
            ->get(['id', 'version', 'evolution_type', 'changelog', 'parent_version_id', 'created_at']);

        if ($versions->isEmpty()) {
            return Response::text(json_encode([
                'skill_id' => $validated['skill_id'],
                'total_versions' => 0,
                'versions' => [],
            ]));
        }

        return Response::text(json_encode([
            'skill_id' => $validated['skill_id'],
            'total_versions' => $versions->count(),
            'versions' => $versions->map(fn ($v) => [
                'id' => $v->id,
                'version' => $v->version,
                'evolution_type' => $v->evolution_type ?? 'manual',
                'changelog' => $v->changelog,
                'parent_version_id' => $v->parent_version_id,
                'created_at' => $v->created_at?->toIso8601String(),
            ])->values()->toArray(),
        ]));
    }
}
