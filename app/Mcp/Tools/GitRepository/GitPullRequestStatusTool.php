<?php

namespace App\Mcp\Tools\GitRepository;

use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationRouter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GitPullRequestStatusTool extends Tool
{
    protected string $name = 'git_pr_status';

    protected string $description = 'Get the current status of a pull request: CI check results, review approvals, and mergeability. Use this before git_pr_merge to verify the PR is ready.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'repository_id' => $schema->string()
                ->description('Repository UUID')
                ->required(),
            'pr_number' => $schema->integer()
                ->description('Pull request number')
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
            $status = $client->getPullRequestStatus((int) $request->get('pr_number'));

            $ready = $status['mergeable'] !== false
                && $status['ci_passing']
                && ($status['reviews_approved'] || count($status['checks']) === 0);

            return Response::text(json_encode([
                'pr_number' => $request->get('pr_number'),
                'state' => $status['state'],
                'mergeable' => $status['mergeable'],
                'ci_passing' => $status['ci_passing'],
                'reviews_approved' => $status['reviews_approved'],
                'ready_to_merge' => $ready,
                'checks' => $status['checks'],
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
