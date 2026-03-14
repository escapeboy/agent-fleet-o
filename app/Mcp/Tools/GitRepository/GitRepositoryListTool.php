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
class GitRepositoryListTool extends Tool
{
    protected string $name = 'git_repository_list';

    protected string $description = 'List git repositories connected to the team. Returns id, name, url, provider, mode, status, and default_branch.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'provider' => $schema->string()
                ->description('Filter by provider: github, gitlab, bitbucket, gitea, generic')
                ->enum(['github', 'gitlab', 'bitbucket', 'gitea', 'generic']),
            'mode' => $schema->string()
                ->description('Filter by mode: api_only, sandbox, bridge')
                ->enum(['api_only', 'sandbox', 'bridge']),
            'status' => $schema->string()
                ->description('Filter by status: active, disabled, error')
                ->enum(['active', 'disabled', 'error']),
            'limit' => $schema->integer()
                ->description('Max results (default 15, max 100)')
                ->default(15),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = GitRepository::query()->orderBy('name');

        if ($provider = $request->get('provider')) {
            $query->where('provider', $provider);
        }

        if ($mode = $request->get('mode')) {
            $query->where('mode', $mode);
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $limit = min((int) ($request->get('limit', 15)), 100);

        $repos = $query->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $repos->count(),
            'repositories' => $repos->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'url' => $r->url,
                'provider' => $r->provider->value,
                'mode' => $r->mode->value,
                'status' => $r->status->value,
                'default_branch' => $r->default_branch,
                'last_ping_at' => $r->last_ping_at?->toIso8601String(),
            ])->toArray(),
        ]));
    }
}
