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
class GitFileReadTool extends Tool
{
    protected string $name = 'git_file_read';

    protected string $description = 'Read the content of a file from a git repository.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'repository_id' => $schema->string()
                ->description('Repository UUID')
                ->required(),
            'path' => $schema->string()
                ->description('File path relative to repo root (e.g. "src/app.php")')
                ->required(),
            'ref' => $schema->string()
                ->description('Git ref: branch name, tag, or commit SHA (default: HEAD)')
                ->default('HEAD'),
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
            $content = $client->readFile(
                $request->get('path'),
                $request->get('ref', 'HEAD'),
            );

            return Response::text(json_encode([
                'path' => $request->get('path'),
                'ref' => $request->get('ref', 'HEAD'),
                'content' => $content,
                'length' => strlen($content),
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
