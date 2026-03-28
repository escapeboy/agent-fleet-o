<?php

namespace App\Mcp\Tools\Project;

use App\Domain\Project\Models\ProjectRun;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ProjectRunGetTool extends Tool
{
    protected string $name = 'project_run_get';

    protected string $description = 'Get full details of a specific project run including output summary, error message, and links to the underlying experiment or crew execution.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'run_id' => $schema->string()
                ->description('The project run UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['run_id' => 'required|string']);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        $run = ProjectRun::whereHas('project', fn ($q) => $q->where('team_id', $teamId))
            ->with(['experiment', 'crewExecution', 'artifacts'])
            ->find($validated['run_id']);

        if (! $run) {
            return Response::error('Project run not found.');
        }

        $result = [
            'id' => $run->id,
            'project_id' => $run->project_id,
            'run_number' => $run->run_number,
            'status' => $run->status->value,
            'trigger' => $run->trigger,
            'input_data' => $run->input_data,
            'output_summary' => $run->output_summary,
            'spend_credits' => $run->spend_credits,
            'error_message' => $run->error_message,
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'duration' => $run->durationForHumans(),
            'experiment_id' => $run->experiment_id,
            'crew_execution_id' => $run->crew_execution_id,
            'artifact_count' => $run->artifacts->count(),
        ];

        // Include experiment summary if available
        if ($run->experiment) {
            $result['experiment'] = [
                'id' => $run->experiment->id,
                'status' => $run->experiment->status->value,
                'title' => $run->experiment->title,
            ];
        }

        // Include crew execution summary if available
        if ($run->crewExecution) {
            $result['crew_execution'] = [
                'id' => $run->crewExecution->id,
                'status' => $run->crewExecution->status->value,
            ];
        }

        return Response::text(json_encode($result));
    }
}
