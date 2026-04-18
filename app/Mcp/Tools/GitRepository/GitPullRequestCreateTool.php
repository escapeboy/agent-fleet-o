<?php

namespace App\Mcp\Tools\GitRepository;

use App\Domain\Approval\Actions\CreateApprovalRequestAction;
use App\Domain\GitRepository\Models\GitPullRequest;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationRouter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class GitPullRequestCreateTool extends Tool
{
    protected string $name = 'git_pr_create';

    protected string $description = 'Create a pull request in a git repository. If the repository has require_approval enabled, creates an ApprovalRequest for human review before merge.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'repository_id' => $schema->string()
                ->description('Repository UUID')
                ->required(),
            'title' => $schema->string()
                ->description('Pull request title')
                ->required(),
            'body' => $schema->string()
                ->description('Pull request description/body'),
            'head' => $schema->string()
                ->description('Source branch (the branch with changes)')
                ->required(),
            'base' => $schema->string()
                ->description('Target branch (defaults to repository default_branch)'),
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
            $base = $request->get('base') ?: $repo->default_branch;

            $prData = $client->createPullRequest(
                $request->get('title'),
                $request->get('body', ''),
                $request->get('head'),
                $base,
            );

            // Record the PR in the platform
            $gitPr = GitPullRequest::create([
                'git_repository_id' => $repo->id,
                'agent_id' => null,
                'title' => $prData['title'] ?? $request->get('title'),
                'body' => $request->get('body', ''),
                'branch' => $request->get('head'),
                'base_branch' => $base,
                'pr_number' => $prData['pr_number'],
                'pr_url' => $prData['pr_url'],
                'status' => 'open',
            ]);

            $approvalRequestId = null;

            // Create approval request if require_approval is enabled
            if ($repo->config['pr']['require_approval'] ?? false) {
                try {
                    $approvalRequest = app(CreateApprovalRequestAction::class)->execute(
                        teamId: $repo->team_id,
                        subject: "Merge PR: {$prData['title']}",
                        context: [
                            'pr_url' => $prData['pr_url'],
                            'pr_number' => $prData['pr_number'],
                            'repository' => $repo->name,
                            'head' => $request->get('head'),
                            'base' => $base,
                        ],
                    );

                    $gitPr->update(['approval_request_id' => $approvalRequest->id]);
                    $approvalRequestId = $approvalRequest->id;
                } catch (\Throwable) {
                    // Approval creation failure should not block the PR
                }
            }

            return Response::text(json_encode([
                'success' => true,
                'pr_number' => $prData['pr_number'],
                'pr_url' => $prData['pr_url'],
                'platform_pr_id' => $gitPr->id,
                'approval_request_id' => $approvalRequestId,
                'requires_approval' => $approvalRequestId !== null,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
