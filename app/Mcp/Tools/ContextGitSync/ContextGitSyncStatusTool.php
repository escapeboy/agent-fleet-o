<?php

namespace App\Mcp\Tools\ContextGitSync;

use App\Domain\GitRepository\Models\ContextGitSync;
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
class ContextGitSyncStatusTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'context_git_sync_status';

    protected string $description = 'Get the current context → Git sync configuration and last push status for this team.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;

        if (! $teamId) {
            return $this->permissionDeniedError('No team context.');
        }

        $sync = ContextGitSync::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->first();

        if (! $sync) {
            return Response::text(json_encode(['configured' => false]));
        }

        return Response::text(json_encode([
            'configured' => true,
            'git_repository_id' => $sync->git_repository_id,
            'branch' => $sync->branch,
            'sync_artifacts' => $sync->sync_artifacts,
            'sync_memory' => $sync->sync_memory,
            'last_pushed_sha' => $sync->last_pushed_sha,
            'last_pushed_at' => $sync->last_pushed_at?->toIso8601String(),
        ]));
    }
}
