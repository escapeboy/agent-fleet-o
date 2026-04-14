<?php

namespace App\Mcp\Tools\Crew;

use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Jobs\ExecuteCrewJob;
use App\Domain\Crew\Models\CrewExecution;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class CrewExecutionResumeTool extends Tool
{
    protected string $name = 'crew_execution_resume';

    protected string $description = 'Resume a paused crew execution. Resets status to planning and re-dispatches the orchestrator job to continue from pending tasks.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'execution_id' => $schema->string()
                ->description('The crew execution UUID to resume')
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

        if ($execution->status !== CrewExecutionStatus::Paused) {
            return Response::error("Cannot resume execution in status '{$execution->status->value}'. Only paused executions can be resumed.");
        }

        $execution->update(['status' => CrewExecutionStatus::Planning]);

        ExecuteCrewJob::dispatch($execution->id, $execution->team_id);

        return Response::text(json_encode([
            'success' => true,
            'execution_id' => $execution->id,
            'status' => CrewExecutionStatus::Planning->value,
            'note' => 'Execution queued for resumption. The orchestrator will continue from pending tasks.',
        ]));
    }
}
