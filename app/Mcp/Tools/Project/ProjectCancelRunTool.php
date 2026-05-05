<?php

namespace App\Mcp\Tools\Project;

use App\Domain\Project\Enums\ProjectRunStatus;
use App\Domain\Project\Models\ProjectRun;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('destructive')]
class ProjectCancelRunTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'project_run_cancel';

    protected string $description = 'Cancel an active project run.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'run_id' => $schema->string()->description('The project run ID to cancel.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $run = ProjectRun::withoutGlobalScopes()
            ->whereHas('project', fn ($q) => $q->where('team_id', $teamId))
            ->find($request->get('run_id'));

        if (! $run) {
            return $this->notFoundError('project run');
        }

        if ($run->status->isTerminal()) {
            return $this->failedPreconditionError('Project run is already in a terminal state: '.$run->status->value);
        }

        $run->status = ProjectRunStatus::Cancelled;
        $run->save();

        return Response::text(json_encode([
            'success' => true,
            'id' => $run->id,
            'status' => $run->status->value,
        ]));
    }
}
