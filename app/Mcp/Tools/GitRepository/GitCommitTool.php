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
class GitCommitTool extends Tool
{
    protected string $name = 'git_commit';

    protected string $description = 'Commit multiple file changes atomically to a git repository branch. All changes are applied in a single commit.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'repository_id' => $schema->string()
                ->description('Repository UUID')
                ->required(),
            'branch' => $schema->string()
                ->description('Branch to commit to (defaults to repository default_branch)'),
            'message' => $schema->string()
                ->description('Commit message')
                ->required(),
            'changes' => $schema->array()
                ->description('Array of file changes: [{path, content}] or [{path, deleted: true}]')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'repository_id' => 'required|string',
            'branch' => 'nullable|string|max:255',
            'message' => 'required|string|max:2048',
            'changes' => 'required|array|min:1',
            'changes.*.path' => 'required|string',
            'changes.*.content' => 'required_without:changes.*.deleted|string',
            'changes.*.deleted' => 'nullable|boolean',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }
        $repo = GitRepository::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['repository_id']);

        if (! $repo) {
            return Response::error('Repository not found.');
        }

        try {
            $client = app(GitOperationRouter::class)->resolve($repo);
            $branch = $validated['branch'] ?? $repo->default_branch;
            $sha = $client->commit($validated['changes'], $validated['message'], $branch);

            return Response::text(json_encode([
                'success' => true,
                'commit_sha' => $sha,
                'branch' => $branch,
                'files_changed' => count($validated['changes']),
                'message' => $validated['message'],
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
