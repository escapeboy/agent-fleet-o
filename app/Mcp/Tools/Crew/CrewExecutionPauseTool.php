<?php

namespace App\Mcp\Tools\Crew;

use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Models\CrewExecution;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class CrewExecutionPauseTool extends Tool
{
    protected string $name = 'crew_execution_pause';

    protected string $description = 'Pause an active crew execution (planning or executing). In-flight tasks will complete, but no new tasks will be dispatched. Use crew_execution_resume to continue.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'execution_id' => $schema->string()
                ->description('The crew execution UUID to pause')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'execution_id' => 'required|string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $execution = CrewExecution::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['execution_id']);

        if (! $execution) {
            return Response::error('Crew execution not found.');
        }

        if (! $execution->status->isActive()) {
            return Response::error("Cannot pause execution in status '{$execution->status->value}'. Only planning/executing executions can be paused.");
        }

        $execution->update(['status' => CrewExecutionStatus::Paused]);

        return Response::text(json_encode([
            'success' => true,
            'execution_id' => $execution->id,
            'status' => CrewExecutionStatus::Paused->value,
            'note' => 'Execution paused. In-flight tasks will still complete. Use crew_execution_resume to continue.',
        ]));
    }
}
