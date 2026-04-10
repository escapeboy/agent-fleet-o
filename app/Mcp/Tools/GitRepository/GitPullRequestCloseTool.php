<?php

namespace App\Mcp\Tools\GitRepository;

use App\Domain\GitRepository\Models\GitPullRequest;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationRouter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class GitPullRequestCloseTool extends Tool
{
    protected string $name = 'git_pr_close';

    protected string $description = 'Close (abandon) a pull request without merging it. The branch is not deleted.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'repository_id' => $schema->string()
                ->description('Repository UUID')
                ->required(),
            'pr_number' => $schema->integer()
                ->description('Pull request number to close')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }
        $repo = GitRepository::withoutGlobalScopes()->where('team_id', $teamId)->find($request->get('repository_id'));

        if (! $repo) {
            return Response::error('Repository not found.');
        }

        try {
            $client = app(GitOperationRouter::class)->resolve($repo);
            $prNumber = (int) $request->get('pr_number');

            $client->closePullRequest($prNumber);

            // Update platform record if it exists
            GitPullRequest::where('git_repository_id', $repo->id)
                ->where('pr_number', (string) $prNumber)
                ->update(['status' => 'closed']);

            return Response::text(json_encode([
                'success' => true,
                'pr_number' => $prNumber,
                'status' => 'closed',
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
