<?php

namespace App\Mcp\Tools\GitRepository;

use App\Domain\GitRepository\Actions\TestGitConnectionAction;
use App\Domain\GitRepository\Models\GitRepository;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GitRepositoryTestTool extends Tool
{
    protected string $name = 'git_repository_test';

    protected string $description = 'Test connectivity to a git repository. Updates the last_ping_at and status fields.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->description('Repository UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $repo = GitRepository::find($request->get('id'));

        if (! $repo) {
            return Response::error('Repository not found.');
        }

        $result = app(TestGitConnectionAction::class)->execute($repo);

        return Response::text(json_encode($result));
    }
}
