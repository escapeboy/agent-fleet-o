<?php

namespace App\Domain\Agent\Pipeline\Middleware;

use App\Domain\Agent\Pipeline\AgentExecutionContext;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\RepoMapGenerator;
use App\Models\GlobalSetting;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Injects a compressed repo map (file tree + key signatures) into the agent's system prompt
 * when the agent is linked to one or more GitRepositories.
 *
 * The repo map is cached in Redis by (repository_id, HEAD sha) with a 1-hour TTL.
 * If the git_repository_ids config is absent on the agent, or the feature flag is off,
 * the middleware is a no-op.
 */
class InjectRepoMapContext
{
    /** @phpstan-ignore-next-line property.onlyWritten */
    public function __construct(
        private readonly RepoMapGenerator $generator,
    ) {}

    public function handle(AgentExecutionContext $ctx, Closure $next): AgentExecutionContext
    {
        // Feature flag guard
        if (! GlobalSetting::get('repo_map_context_enabled', true)) {
            return $next($ctx);
        }

        // Agents link git repos via config['git_repository_ids'] (an array of UUIDs)
        $gitRepoIds = $ctx->agent->config['git_repository_ids'] ?? [];

        if (empty($gitRepoIds)) {
            return $next($ctx);
        }

        $parts = [];

        foreach ($gitRepoIds as $gitRepoId) {
            try {
                $repoMap = $this->resolveRepoMap($gitRepoId, $ctx->teamId);
                if ($repoMap !== null) {
                    $parts[] = $repoMap;
                }
            } catch (\Throwable $e) {
                // Graceful degradation — repo map context is additive, never blocking
                Log::warning('InjectRepoMapContext: failed to generate repo map', [
                    'agent_id' => $ctx->agent->id,
                    'git_repository_id' => $gitRepoId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (! empty($parts)) {
            $combined = implode("\n\n", $parts);
            $ctx->systemPromptParts[] = "<repository_context>\n{$combined}\n</repository_context>";
        }

        return $next($ctx);
    }

    private function resolveRepoMap(string $gitRepoId, string $teamId): ?string
    {
        $repo = GitRepository::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($gitRepoId);

        if (! $repo) {
            return null;
        }

        // Derive the local clone path from the repo config (field: local_path or cloned_path)
        $config = is_array($repo->config) ? $repo->config : [];
        $repoPath = $config['local_path'] ?? $config['cloned_path'] ?? null;

        if (! $repoPath || ! is_dir((string) $repoPath)) {
            return null;
        }

        // Resolve canonical path to prevent path traversal via user-controlled JSONB config
        $repoPath = realpath((string) $repoPath);

        if ($repoPath === false) {
            return null;
        }

        $baseClonesPath = rtrim((string) config('git.clones_base_path', storage_path('app/repos')), '/');

        if (! str_starts_with($repoPath, $baseClonesPath . '/') && $repoPath !== $baseClonesPath) {
            Log::warning('InjectRepoMapContext: repo path outside base clones directory (possible path traversal)', [
                'resolved_path' => $repoPath,
                'base_path' => $baseClonesPath,
            ]);

            return null;
        }
        $headSha = $this->resolveHeadSha($repoPath);
        $cacheKey = "repo_map:{$gitRepoId}:".($headSha ?? 'unknown');

        return Cache::remember($cacheKey, 3600, function () use ($repoPath, $repo) {
            $map = $this->generator->generate($repoPath);

            return "## Repository: {$repo->name} ({$repo->url})\n\n{$map}";
        });
    }

    private function resolveHeadSha(string $repoPath): ?string
    {
        try {
            $process = new Process(['git', '-C', $repoPath, 'rev-parse', 'HEAD'], timeout: 10);
            $process->run();

            if ($process->isSuccessful()) {
                return trim($process->getOutput());
            }
        } catch (\Throwable) {
            // Silently ignore
        }

        return null;
    }
}
