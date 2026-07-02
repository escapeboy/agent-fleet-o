<?php

namespace App\Mcp\Tools\ContextGitSync;

use App\Domain\GitRepository\Jobs\PushContextToGitJob;
use App\Domain\GitRepository\Models\ContextGitSync;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class ContextGitExportNowTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'context_git_export_now';

    protected string $description = 'Trigger an immediate push of this team\'s context (artifacts + memory) to its linked Git repository.';

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
            return $this->failedPreconditionError('Context Git sync is not configured for this team.');
        }

        PushContextToGitJob::dispatch($sync->id);

        return Response::text(json_encode([
            'queued' => true,
            'sync_id' => $sync->id,
            'branch' => $sync->branch,
        ]));
    }
}
