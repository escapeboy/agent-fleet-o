<?php

namespace App\Mcp\Tools\Project;

use App\Domain\Project\Models\Project;
use App\Domain\Project\Services\ProjectHealthEvaluator;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ProjectHealthTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'project_health';

    protected string $description = 'Diagnose why a project is or is not running: returns a state (off/ok/degraded/stopped) and a list of reasons, each with a concrete next step.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()
                ->description('The project UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['project_id' => 'required|string']);

        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $project = Project::query()->where('team_id', $teamId)->find($validated['project_id']);

        if (! $project) {
            return $this->notFoundError('project', $validated['project_id']);
        }

        return Response::text(json_encode(app(ProjectHealthEvaluator::class)->evaluate($project)->toArray()));
    }
}
