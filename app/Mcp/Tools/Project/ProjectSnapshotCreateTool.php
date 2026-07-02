<?php

namespace App\Mcp\Tools\Project;

use App\Domain\Project\Actions\CreateProjectSnapshotAction;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Models\Team;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class ProjectSnapshotCreateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'project_snapshot_create';

    protected string $description = 'Capture a restorable snapshot of a project\'s current configuration (settings, schedule, milestones). Use before risky config changes.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()
                ->description('The project UUID')
                ->required(),
            'label' => $schema->string()
                ->description('Optional human-readable label for the snapshot (max 120 chars)'),
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
            'label' => 'nullable|string|max:120',
        ]);

        $project = Project::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['project_id']);

        if (! $project) {
            return $this->notFoundError('project');
        }

        $snapshot = app(CreateProjectSnapshotAction::class)->execute(
            project: $project,
            label: $validated['label'] ?? null,
            createdBy: Team::ownerIdFor($teamId),
        );

        return Response::text(json_encode([
            'id' => $snapshot->id,
            'project_id' => $snapshot->project_id,
            'label' => $snapshot->label,
            'created_at' => $snapshot->created_at?->toIso8601String(),
        ]));
    }
}
