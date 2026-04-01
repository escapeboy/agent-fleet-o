<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Skill\Models\Skill;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ListSkillsTool implements Tool
{
    public function name(): string
    {
        return 'list_skills';
    }

    public function description(): string
    {
        return 'List available skills with optional type filter';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->description('Filter by type (e.g. llm, connector, rule, hybrid)'),
            'limit' => $schema->integer()->description('Max results to return (default 10)'),
        ];
    }

    public function handle(Request $request): string
    {
        $query = Skill::query()->orderBy('name');

        if ($request->get('type')) {
            $query->where('type', $request->get('type'));
        }

        $skills = $query->limit($request->get('limit', 10))->get(['id', 'name', 'type', 'status']);

        return json_encode([
            'count' => $skills->count(),
            'skills' => $skills->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'type' => $s->type->value,
                'status' => $s->status->value,
            ])->toArray(),
        ]);
    }
}
