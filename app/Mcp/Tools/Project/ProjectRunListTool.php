<?php

namespace App\Mcp\Tools\Project;

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectRun;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ProjectRunListTool extends Tool
{
    protected string $name = 'project_run_list';

    protected string $description = 'List all runs for a project with optional status filter. Returns run history with status, timing, and experiment/crew execution IDs for drill-down.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()
                ->description('The project UUID')
                ->required(),
            'status' => $schema->string()
                ->description('Filter by run status')
                ->enum(['pending', 'running', 'completed', 'failed', 'cancelled']),
            'limit' => $schema->integer()
                ->description('Max results to return (default 20, max 100)')
                ->default(20),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'project_id' => 'required|string',
            'status' => 'nullable|string|in:pending,running,completed,failed,cancelled',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $teamId = auth()->user()?->current_team_id;

        $project = Project::where('team_id', $teamId)->find($validated['project_id']);

        if (! $project) {
            return Response::error('Project not found.');
        }

        $query = ProjectRun::where('project_id', $project->id)
            ->orderByDesc('run_number');

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $limit = min((int) ($validated['limit'] ?? 20), 100);
        $runs = $query->limit($limit)->get();

        return Response::text(json_encode([
            'project_id' => $project->id,
            'project_title' => $project->title,
            'count' => $runs->count(),
            'runs' => $runs->map(fn ($r) => [
                'id' => $r->id,
                'run_number' => $r->run_number,
                'status' => $r->status->value,
                'trigger' => $r->trigger,
                'experiment_id' => $r->experiment_id,
                'crew_execution_id' => $r->crew_execution_id,
                'spend_credits' => $r->spend_credits,
                'error_message' => $r->error_message,
                'started_at' => $r->started_at?->toIso8601String(),
                'completed_at' => $r->completed_at?->toIso8601String(),
                'duration' => $r->durationForHumans(),
            ])->toArray(),
        ]));
    }
}
