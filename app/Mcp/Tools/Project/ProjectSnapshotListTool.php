<?php

namespace App\Mcp\Tools\Project;

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectSnapshot;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class ProjectSnapshotListTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'project_snapshot_list';

    protected string $description = 'List configuration snapshots for a project, newest first.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()
                ->description('The project UUID')
                ->required(),
            'limit' => $schema->integer()
                ->description('Maximum snapshots to return (default: 20, max: 100)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;

        if (! $teamId) {
            return $this->permissionDeniedError('No team context.');
        }

        $validated = $request->validate([
            'project_id' => 'required|string',
            'limit' => 'integer|min:1|max:100',
        ]);

        $project = Project::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['project_id']);

        if (! $project) {
            return $this->notFoundError('project');
        }

        $snapshots = ProjectSnapshot::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('project_id', $project->id)
            ->orderByDesc('created_at')
            ->limit($validated['limit'] ?? 20)
            ->get(['id', 'label', 'created_by', 'restored_at', 'created_at']);

        return Response::text(json_encode([
            'project_id' => $project->id,
            'count' => $snapshots->count(),
            'snapshots' => $snapshots->map(fn ($s) => [
                'id' => $s->id,
                'label' => $s->label,
                'restored_at' => $s->restored_at?->toIso8601String(),
                'created_at' => $s->created_at?->toIso8601String(),
            ])->values()->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
