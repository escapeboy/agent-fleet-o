<?php

namespace App\Mcp\Tools\Project;

use App\Domain\Project\Actions\RestoreProjectSnapshotAction;
use App\Domain\Project\Models\ProjectSnapshot;
use App\Domain\Shared\Models\Team;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('destructive')]
class ProjectSnapshotRestoreTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'project_snapshot_restore';

    protected string $description = 'Restore a project\'s configuration from a snapshot. Overwrites current settings and schedule. Refused while the project has an active run.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'snapshot_id' => $schema->string()
                ->description('The project snapshot UUID to restore')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        if (! $teamId) {
            return $this->permissionDeniedError('No team context.');
        }

        $validated = $request->validate([
            'snapshot_id' => 'required|string',
        ]);

        $snapshot = ProjectSnapshot::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['snapshot_id']);

        if (! $snapshot) {
            return $this->notFoundError('snapshot');
        }

        $result = app(RestoreProjectSnapshotAction::class)->execute(
            snapshot: $snapshot,
            userId: Team::ownerIdFor($teamId),
        );

        if (! $result['restored']) {
            return $this->invalidArgumentError($result['reason']);
        }

        return Response::text(json_encode([
            'restored' => true,
            'snapshot_id' => $snapshot->id,
            'project_id' => $snapshot->project_id,
        ]));
    }
}
