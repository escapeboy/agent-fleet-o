<?php

namespace App\Mcp\Tools\GitRepository;

use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationRouter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GitFileWriteTool extends Tool
{
    protected string $name = 'git_file_write';

    protected string $description = 'Write a single file to a git repository and commit the change. Creates the file if it does not exist.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'repository_id' => $schema->string()
                ->description('Repository UUID')
                ->required(),
            'path' => $schema->string()
                ->description('File path relative to repo root (e.g. "src/app.php")')
                ->required(),
            'content' => $schema->string()
                ->description('Full file content to write')
                ->required(),
            'message' => $schema->string()
                ->description('Commit message')
                ->required(),
            'branch' => $schema->string()
                ->description('Branch to commit to (defaults to repository default_branch)'),
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
            $branch = $request->get('branch') ?: $repo->default_branch;
            $sha = $client->writeFile(
                $request->get('path'),
                $request->get('content'),
                $request->get('message'),
                $branch,
            );

            return Response::text(json_encode([
                'success' => true,
                'path' => $request->get('path'),
                'branch' => $branch,
                'commit_sha' => $sha,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
