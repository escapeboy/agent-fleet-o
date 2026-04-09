<?php

namespace App\Mcp\Tools\GitRepository;

use App\Domain\GitRepository\Actions\CreateGitRepositoryAction;
use App\Domain\GitRepository\Enums\GitProvider;
use App\Domain\GitRepository\Enums\GitRepoMode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GitRepositoryCreateTool extends Tool
{
    protected string $name = 'git_repository_create';

    protected string $description = <<<'DESC'
Connect a new git repository. Three modes available:

api_only  — GitHub/GitLab REST API (no cloning). Best for cloud agents. Requires a credential with a PAT token.
sandbox   — Ephemeral compute container that clones, edits, and destroys. Supports test execution.
bridge    — Route operations through local Bridge daemon. Best for self-hosted repos.

provider is auto-detected from URL if not specified.
DESC;

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Friendly name for the repository')
                ->required(),
            'url' => $schema->string()
                ->description('Repository URL (HTTPS or SSH)')
                ->required(),
            'mode' => $schema->string()
                ->description('Editing mode: api_only, sandbox, bridge (default: api_only)')
                ->enum(['api_only', 'sandbox', 'bridge'])
                ->default('api_only'),
            'provider' => $schema->string()
                ->description('Git provider (auto-detected from URL if omitted): github, gitlab, bitbucket, gitea, generic')
                ->enum(['github', 'gitlab', 'bitbucket', 'gitea', 'generic']),
            'default_branch' => $schema->string()
                ->description('Default branch name (default: main)')
                ->default('main'),
            'credential_id' => $schema->string()
                ->description('UUID of a Credential containing the PAT/SSH key for authentication'),
            'config' => $schema->object()
                ->description('Mode-specific config. sandbox: {provider, instance_type, run_tests, test_command}. bridge: {repo_name, working_directory}. pr: {require_approval}'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'url' => 'required|string|max:2048',
            'mode' => 'nullable|string|in:api_only,sandbox,bridge',
            'provider' => 'nullable|string|in:github,gitlab,bitbucket,gitea,generic',
            'default_branch' => 'nullable|string|max:255',
            'credential_id' => ['nullable', 'uuid',
                Rule::exists('credentials', 'id')->where('team_id', $teamId)],
            'config' => 'nullable|array',
        ]);

        try {
            $repo = app(CreateGitRepositoryAction::class)->execute(
                teamId: $teamId,
                name: $validated['name'],
                url: $validated['url'],
                mode: GitRepoMode::from($validated['mode'] ?? 'api_only'),
                provider: isset($validated['provider']) ? GitProvider::from($validated['provider']) : null,
                defaultBranch: $validated['default_branch'] ?? 'main',
                credentialId: $validated['credential_id'] ?? null,
                config: $validated['config'] ?? [],
            );

            return Response::text(json_encode([
                'success' => true,
                'repository_id' => $repo->id,
                'name' => $repo->name,
                'provider' => $repo->provider->value,
                'mode' => $repo->mode->value,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
