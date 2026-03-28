<?php

namespace App\Mcp\Tools\GitRepository;

use App\Domain\GitRepository\Actions\DeleteGitRepositoryAction;
use App\Domain\GitRepository\Models\GitRepository;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GitRepositoryDeleteTool extends Tool
{
    protected string $name = 'git_repository_delete';

    protected string $description = 'Delete a git repository connection (soft delete). Does not affect the remote repository.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->description('Repository UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }
        $repo = GitRepository::withoutGlobalScopes()->where('team_id', $teamId)->find($request->get('id'));

        if (! $repo) {
            return Response::error('Repository not found.');
        }

        try {
            app(DeleteGitRepositoryAction::class)->execute($repo);

            return Response::text(json_encode(['success' => true, 'message' => 'Repository connection deleted.']));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
