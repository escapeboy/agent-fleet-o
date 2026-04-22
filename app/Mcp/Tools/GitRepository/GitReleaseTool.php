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
class GitReleaseTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'git_release_create';

    protected string $description = 'Create a release with a version tag and release notes in a git repository. Creates the tag and publishes the release in one step.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'repository_id' => $schema->string()
                ->description('Repository UUID')
                ->required(),
            'tag_name' => $schema->string()
                ->description('Version tag (e.g. "v1.2.0" or "1.2.0")')
                ->required(),
            'name' => $schema->string()
                ->description('Release title (e.g. "Release 1.2.0")')
                ->required(),
            'body' => $schema->string()
                ->description('Release notes in Markdown format')
                ->required(),
            'target_commitish' => $schema->string()
                ->description('Branch or commit SHA the tag is created from (default: repository default branch)'),
            'draft' => $schema->boolean()
                ->description('Create as draft release (default: false)'),
            'prerelease' => $schema->boolean()
                ->description('Mark as pre-release (default: false)'),
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

            $result = $client->createRelease(
                $request->get('tag_name'),
                $request->get('name'),
                $request->get('body'),
                $request->get('target_commitish') ?: $repo->default_branch,
                (bool) $request->get('draft', false),
                (bool) $request->get('prerelease', false),
            );

            return Response::text(json_encode([
                'success' => true,
                'id' => $result['id'],
                'tag_name' => $result['tag_name'],
                'name' => $result['name'],
                'url' => $result['url'],
                'draft' => $result['draft'],
                'prerelease' => $result['prerelease'],
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
