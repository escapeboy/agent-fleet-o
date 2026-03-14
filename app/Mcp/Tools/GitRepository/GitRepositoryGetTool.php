<?php

namespace App\Mcp\Tools\GitRepository;

use App\Domain\GitRepository\Models\GitRepository;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class GitRepositoryGetTool extends Tool
{
    protected string $name = 'git_repository_get';

    protected string $description = 'Get a single git repository by ID, including its full configuration.';

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

        return Response::text(json_encode([
            'id' => $repo->id,
            'name' => $repo->name,
            'url' => $repo->url,
            'provider' => $repo->provider->value,
            'mode' => $repo->mode->value,
            'status' => $repo->status->value,
            'default_branch' => $repo->default_branch,
            'credential_id' => $repo->credential_id,
            'config' => $repo->config,
            'last_ping_at' => $repo->last_ping_at?->toIso8601String(),
            'last_ping_status' => $repo->last_ping_status,
            'created_at' => $repo->created_at->toIso8601String(),
        ]));
    }
}
