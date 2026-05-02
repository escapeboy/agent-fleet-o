<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Skill\Jobs\ExtractSkillFromTrajectoryJob;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class SkillTrajectoryExtractTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'skill_trajectory_extract';

    protected string $description = 'Queue extraction of a reusable skill from a completed crew or agent execution trajectory.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'execution_id' => $schema->string()
                ->description('The crew or agent execution UUID')
                ->required(),
            'execution_type' => $schema->string()
                ->description('Type of execution: crew or agent')
                ->enum(['crew', 'agent'])
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $executionId = $request->get('execution_id');
        $executionType = $request->get('execution_type');

        $execution = $executionType === 'crew'
            ? CrewExecution::withoutGlobalScopes()->where('team_id', $teamId)->find($executionId)
            : AgentExecution::withoutGlobalScopes()->where('team_id', $teamId)->find($executionId);

        if (! $execution) {
            return $this->notFoundError('execution');
        }

        ExtractSkillFromTrajectoryJob::dispatch($executionId, $executionType);

        return Response::text(json_encode([
            'queued' => true,
            'execution_id' => $executionId,
            'execution_type' => $executionType,
        ]));
    }
}
