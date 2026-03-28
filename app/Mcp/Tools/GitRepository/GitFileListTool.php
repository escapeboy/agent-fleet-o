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
class GitFileListTool extends Tool
{
    protected string $name = 'git_file_list';

    protected string $description = 'List files and directories at a given path in a git repository.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'repository_id' => $schema->string()
                ->description('Repository UUID')
                ->required(),
            'path' => $schema->string()
                ->description('Directory path (default: "/" for root)')
                ->default('/'),
            'ref' => $schema->string()
                ->description('Git ref: branch, tag, or commit SHA (default: HEAD)')
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
            $files = $client->listFiles(
                $request->get('path', '/'),
                $request->get('ref', 'HEAD'),
            );

            return Response::text(json_encode([
                'path' => $request->get('path', '/'),
                'ref' => $request->get('ref', 'HEAD'),
                'count' => count($files),
                'files' => $files,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
