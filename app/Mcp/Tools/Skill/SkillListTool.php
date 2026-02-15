<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Skill\Models\Skill;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class SkillListTool extends Tool
{
    protected string $name = 'skill_list';

    protected string $description = 'List skills with optional type filter. Returns id, name, type, and status.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()
                ->description('Filter by type: llm, connector, rule, hybrid')
                ->enum(['llm', 'connector', 'rule', 'hybrid']),
            'limit' => $schema->integer()
                ->description('Max results to return (default 10, max 100)')
                ->default(10),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = Skill::query()->orderBy('name');

        if ($type = $request->get('type')) {
            $query->where('type', $type);
        }

        $limit = min((int) ($request->get('limit', 10)), 100);

        $skills = $query->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $skills->count(),
            'skills' => $skills->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'type' => $s->type->value,
                'status' => $s->status->value,
            ])->toArray(),
        ]));
    }
}
