<?php

namespace App\Mcp\Tools\GitRepository;

use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationRouter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class GitPullRequestListTool extends Tool
{
    protected string $name = 'git_pr_list';

    protected string $description = 'List pull requests for a git repository. Also returns platform-tracked PRs created by agents.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'repository_id' => $schema->string()
                ->description('Repository UUID')
                ->required(),
            'state' => $schema->string()
                ->description('PR state filter: open, closed, all (default: open)')
                ->enum(['open', 'closed', 'all'])
                ->default('open'),
        ];
    }

    public function handle(Request $request): Response
    {
        $repo = GitRepository::find($request->get('repository_id'));

        if (! $repo) {
            return Response::error('Repository not found.');
        }

        try {
            $client = app(GitOperationRouter::class)->resolve($repo);
            $state = $request->get('state', 'open');

            $remotePrs = $client->listPullRequests($state === 'all' ? 'all' : $state);

            // Also include platform-tracked PRs
            $platformPrs = $repo->pullRequests()
                ->when($state !== 'all', fn ($q) => $q->where('status', $state))
                ->latest()
                ->limit(30)
                ->get()
                ->map(fn ($pr) => [
                    'pr_number' => $pr->pr_number,
                    'pr_url' => $pr->pr_url,
                    'title' => $pr->title,
                    'branch' => $pr->branch,
                    'status' => $pr->status,
                    'platform_pr_id' => $pr->id,
                    'approval_request_id' => $pr->approval_request_id,
                ])
                ->toArray();

            return Response::text(json_encode([
                'repository_id' => $repo->id,
                'state' => $state,
                'remote_pull_requests' => $remotePrs,
                'platform_pull_requests' => $platformPrs,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
