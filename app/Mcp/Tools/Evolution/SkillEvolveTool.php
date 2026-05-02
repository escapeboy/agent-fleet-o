<?php

namespace App\Mcp\Tools\Evolution;

use App\Domain\Evolution\Actions\EvolveSkillAction;
use App\Domain\Skill\Models\Skill;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class SkillEvolveTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'skill_evolve';

    protected string $description = 'Run GEPA evolution cycle for a skill: generates N system_prompt mutations and queues evaluation. Requires >= 5 scored executions.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'skill_id' => $schema->string()
                ->description('The skill UUID')
                ->required(),
            'population_size' => $schema->integer()
                ->description('Number of variants to generate (default 5, max 10)')
                ->default(5),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $skill = Skill::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($request->get('skill_id'));

        if (! $skill) {
            return $this->notFoundError('skill');
        }

        $populationSize = min((int) ($request->get('population_size', 5)), 10);
        $proposals = app(EvolveSkillAction::class)->execute($skill, $populationSize);

        return Response::text(json_encode([
            'skill_id' => $skill->id,
            'proposals_queued' => $proposals->count(),
            'proposal_ids' => $proposals->pluck('id')->toArray(),
        ]));
    }
}
