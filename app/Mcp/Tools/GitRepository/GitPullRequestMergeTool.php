<?php

namespace App\Mcp\Tools\GitRepository;

use App\Domain\GitRepository\Models\GitPullRequest;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationRouter;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class GitPullRequestMergeTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'git_pr_merge';

    protected string $description = 'Merge a pull request in a git repository. Supports squash, merge, and rebase strategies. By default validates CI status before merging.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'repository_id' => $schema->string()
                ->description('Repository UUID')
                ->required(),
            'pr_number' => $schema->integer()
                ->description('Pull request number to merge')
                ->required(),
            'method' => $schema->string()
                ->description('Merge method: squash (default), merge, or rebase')
                ->enum(['squash', 'merge', 'rebase']),
            'commit_title' => $schema->string()
                ->description('Optional commit title for the merge commit'),
            'commit_message' => $schema->string()
                ->description('Optional commit message body for the merge commit'),
            'force' => $schema->boolean()
                ->description('Skip CI and review validation checks (default: false)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }
        $repo = GitRepository::withoutGlobalScopes()->where('team_id', $teamId)->find($request->get('repository_id'));

        if (! $repo) {
            return $this->notFoundError('repository');
        }

        try {
            $client = app(GitOperationRouter::class)->resolve($repo);
            $prNumber = (int) $request->get('pr_number');
            $force = (bool) $request->get('force', false);

            // Validate PR is mergeable unless force is set
            if (! $force) {
                $status = $client->getPullRequestStatus($prNumber);

                if ($status['mergeable'] === false) {
                    return $this->failedPreconditionError("PR #{$prNumber} is not mergeable (conflicts or state mismatch). Use force=true to bypass.");
                }

                if (! $status['ci_passing'] && $status['mergeable'] !== null) {
                    return $this->failedPreconditionError("PR #{$prNumber} has failing or pending CI checks. Use force=true to merge anyway.");
                }
            }

            $result = $client->mergePullRequest(
                $prNumber,
                $request->get('method', 'squash'),
                $request->get('commit_title'),
                $request->get('commit_message'),
            );

            // Update platform record if it exists
            GitPullRequest::where('git_repository_id', $repo->id)
                ->where('pr_number', (string) $prNumber)
                ->update(['status' => 'merged']);

            return Response::text(json_encode([
                'success' => true,
                'pr_number' => $prNumber,
                'sha' => $result['sha'],
                'merged' => $result['merged'],
                'message' => $result['message'],
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
