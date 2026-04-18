<?php

namespace App\Mcp\Tools\GitRepository;

use App\Domain\GitRepository\Actions\UpdateGitRepositoryAction;
use App\Domain\GitRepository\Enums\GitProvider;
use App\Domain\GitRepository\Enums\GitRepoMode;
use App\Domain\GitRepository\Models\GitRepository;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class GitRepositoryUpdateTool extends Tool
{
    protected string $name = 'git_repository_update';

    protected string $description = 'Update a git repository settings (name, URL, mode, credential, config).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->description('Repository UUID')
                ->required(),
            'name' => $schema->string()
                ->description('New name'),
            'url' => $schema->string()
                ->description('New URL'),
            'mode' => $schema->string()
                ->description('New mode: api_only, sandbox, bridge')
                ->enum(['api_only', 'sandbox', 'bridge']),
            'provider' => $schema->string()
                ->description('New provider')
                ->enum(['github', 'gitlab', 'bitbucket', 'gitea', 'generic']),
            'default_branch' => $schema->string()
                ->description('New default branch'),
            'credential_id' => $schema->string()
                ->description('New credential UUID'),
            'config' => $schema->object()
                ->description('New config (replaces existing)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }
        $repo = GitRepository::withoutGlobalScopes()->where('team_id', $teamId)->find($request->get('id'));

        if (! $repo) {
            return Response::error('Repository not found.');
        }

        try {
            $updated = app(UpdateGitRepositoryAction::class)->execute(
                repo: $repo,
                name: $request->get('name'),
                url: $request->get('url'),
                mode: $request->has('mode') ? GitRepoMode::from($request->get('mode')) : null,
                provider: $request->has('provider') ? GitProvider::from($request->get('provider')) : null,
                defaultBranch: $request->get('default_branch'),
                credentialId: $request->get('credential_id'),
                config: $request->get('config'),
            );

            return Response::text(json_encode([
                'success' => true,
                'repository_id' => $updated->id,
                'name' => $updated->name,
                'mode' => $updated->mode->value,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
