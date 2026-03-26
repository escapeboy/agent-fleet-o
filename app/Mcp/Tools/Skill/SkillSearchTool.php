<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Skill\Actions\ResolveAgentSkillsAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Finds the most relevant skills for a given task description using hybrid
 * BM25 + pgvector semantic retrieval via ResolveAgentSkillsAction.
 */
#[IsReadOnly]
#[IsIdempotent]
class SkillSearchTool extends Tool
{
    public function __construct(
        private readonly ResolveAgentSkillsAction $resolveSkills,
    ) {}

    protected string $name = 'skill_search';

    protected string $description = 'Search for the most relevant skills for a task using hybrid BM25 + semantic retrieval.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_description' => $schema->string()
                ->description('Description of the task to find skills for')
                ->required(),
            'team_id' => $schema->string()
                ->description('Team ID to scope the search')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'task_description' => 'required|string',
            'team_id' => 'required|string',
        ]);

        $skills = $this->resolveSkills->execute($validated['team_id'], $validated['task_description']);

        if ($skills->isEmpty()) {
            return Response::text(json_encode(['count' => 0, 'skills' => []]));
        }

        $result = $skills->map(fn ($skill) => [
            'id' => $skill->id,
            'name' => $skill->name,
            'description' => $skill->description,
            'type' => $skill->type->value,
            'health_score' => round($skill->health_score * 100, 1).'%',
        ])->values()->toArray();

        return Response::text(json_encode(['count' => count($result), 'skills' => $result]));
    }
}
