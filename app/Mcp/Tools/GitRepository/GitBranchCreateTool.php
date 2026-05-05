<?php

namespace App\Mcp\Tools\GitRepository;

use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationRouter;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class GitBranchCreateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'git_branch_create';

    protected string $description = 'Create a new branch in a git repository from an existing branch or commit SHA.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'repository_id' => $schema->string()
                ->description('Repository UUID')
                ->required(),
            'branch' => $schema->string()
                ->description('Name of the new branch to create (e.g. "fix/bug-123")')
                ->required(),
            'from' => $schema->string()
                ->description('Source branch or commit SHA to branch from (defaults to repository default_branch)'),
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
            $from = $request->get('from') ?: $repo->default_branch;
            $client->createBranch($request->get('branch'), $from);

            return Response::text(json_encode([
                'success' => true,
                'branch' => $request->get('branch'),
                'from' => $from,
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
