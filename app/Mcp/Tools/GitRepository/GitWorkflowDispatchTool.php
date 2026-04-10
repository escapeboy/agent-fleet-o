<?php

namespace App\Mcp\Tools\GitRepository;

use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationRouter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class GitWorkflowDispatchTool extends Tool
{
    protected string $name = 'git_workflow_dispatch';

    protected string $description = 'Trigger a GitHub Actions workflow (or GitLab CI pipeline) by workflow file name or ID. Useful for kicking off deployment pipelines after a merge.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'repository_id' => $schema->string()
                ->description('Repository UUID')
                ->required(),
            'workflow_id' => $schema->string()
                ->description('Workflow file name (e.g. "deploy.yml") or workflow ID')
                ->required(),
            'ref' => $schema->string()
                ->description('Branch, tag, or commit SHA to run the workflow on (default: repository default branch)'),
            'inputs' => $schema->object()
                ->description('Key-value pairs of workflow inputs (optional)'),
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
            $ref = $request->get('ref') ?: $repo->default_branch;
            $inputs = $request->get('inputs', []);

            $result = $client->dispatchWorkflow(
                $request->get('workflow_id'),
                $ref,
                is_array($inputs) ? $inputs : [],
            );

            return Response::text(json_encode([
                'success' => true,
                'dispatched' => $result['dispatched'],
                'workflow_id' => $request->get('workflow_id'),
                'ref' => $ref,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
