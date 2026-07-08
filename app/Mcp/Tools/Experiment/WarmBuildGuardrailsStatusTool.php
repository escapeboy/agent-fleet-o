<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Services\WarmBuildCandidatePolicy;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\WritableRootsPolicy;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\Sandbox\WriteJail;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class WarmBuildGuardrailsStatusTool extends Tool
{
    protected string $name = 'warm_build_guardrails_status';

    protected string $description = 'Report the warm-build guardrails resolved for a git repository: best-of-N candidate count, the writable-roots grant (allowed roots + denied globs), and the write-jail (Landlock) state. Read-only, team-scoped.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'git_repository_id' => $schema->string()->description('The UUID of the git repository')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;

        $repo = GitRepository::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->findOrFail($request->get('git_repository_id'));

        $grant = app(WritableRootsPolicy::class)->resolve($repo);
        $jail = app(WriteJail::class);
        $teamAllowed = (bool) Team::withoutGlobalScopes()->whereKey($repo->team_id)->value('warm_build_allowed');

        return Response::text((string) json_encode([
            'git_repository_id' => $repo->id,
            'warm_build' => [
                'globally_enabled' => (bool) config('experiments.warm_build.enabled', false),
                'team_allowed' => $teamAllowed,
            ],
            'best_of_n' => [
                'candidates' => app(WarmBuildCandidatePolicy::class)->count($repo),
                'candidates_max' => (int) config('experiments.warm_build.candidates_max', 3),
                'verify_command_set' => is_string($repo->config['warm_build_verify_command'] ?? null),
            ],
            'writable_roots' => [
                'allowed_roots' => $grant->allowedRoots,
                'denied_globs' => $grant->deniedGlobs,
            ],
            'write_jail' => [
                'enabled' => $jail->enabled(),
                // null = active/enforcing; otherwise why it's inert (disabled/not-linux/launcher-*)
                'inert_reason' => $jail->inertReason(),
            ],
        ], JSON_PRETTY_PRINT));
    }
}
