<?php

namespace App\Mcp\Tools\ContextGitSync;

use App\Domain\GitRepository\Actions\CreateContextGitSyncAction;
use App\Domain\GitRepository\Models\GitRepository;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class ContextGitSyncConfigureTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'context_git_sync_configure';

    protected string $description = 'Configure one-way sync of this team\'s context (artifacts and memory) to a Git repository as versioned markdown files.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'git_repository_id' => $schema->string()
                ->description('UUID of the team\'s GitRepository to sync into')
                ->required(),
            'branch' => $schema->string()
                ->description('Target branch (default: fleetq-context)'),
            'sync_artifacts' => $schema->boolean()
                ->description('Whether to sync artifacts (default: true)'),
            'sync_memory' => $schema->boolean()
                ->description('Whether to sync memory entries (default: true)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        if (! $teamId) {
            return $this->permissionDeniedError('No team context.');
        }

        $validated = $request->validate([
            'git_repository_id' => 'required|string',
            'branch' => 'nullable|string|max:255',
            'sync_artifacts' => 'boolean',
            'sync_memory' => 'boolean',
        ]);

        $repo = GitRepository::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['git_repository_id']);

        if (! $repo) {
            return $this->notFoundError('git_repository');
        }

        $sync = app(CreateContextGitSyncAction::class)->execute(
            teamId: $teamId,
            gitRepositoryId: $repo->id,
            branch: $validated['branch'] ?? 'fleetq-context',
            syncArtifacts: $validated['sync_artifacts'] ?? true,
            syncMemory: $validated['sync_memory'] ?? true,
        );

        return Response::text(json_encode([
            'id' => $sync->id,
            'git_repository_id' => $sync->git_repository_id,
            'branch' => $sync->branch,
            'sync_artifacts' => $sync->sync_artifacts,
            'sync_memory' => $sync->sync_memory,
        ]));
    }
}
