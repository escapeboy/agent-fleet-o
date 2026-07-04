<?php

namespace App\Domain\GitRepository\Services;

use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\Shared\Models\Team;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Warm build sandbox: keeps a persistent per-(team, repo) base clone and hands
 * out isolated, hard-reset worktrees per run — so a build checks out in seconds
 * (git fetch + worktree) instead of re-cloning the whole repository every time.
 *
 * Flag-gated via experiments.warm_build.enabled; when off, nothing calls this.
 * All git invocations use array-form Process (no shell injection).
 *
 * NOTE: pass `$ref` as a fetched ref (e.g. "origin/main") or a commit SHA — a
 * bare local branch name would point at the clone-time state, not the freshly
 * fetched tip.
 */
class WarmRepoManager
{
    /**
     * Global master kill-switch. Off = warm-build is off for EVERY team,
     * regardless of per-team allow.
     */
    public static function enabled(): bool
    {
        return (bool) config('experiments.warm_build.enabled', false);
    }

    /**
     * Per-team gate: warm-build runs for a team ONLY when the global master
     * switch is on AND that team is explicitly trusted. Default OFF — an
     * untrusted external tenant never gets it by flag alone; it requires the
     * team to be marked warm_build_allowed after the hardened path is proven.
     */
    public static function enabledForTeam(?Team $team): bool
    {
        return self::enabled() && $team !== null && (bool) $team->warm_build_allowed;
    }

    /**
     * Provision an isolated worktree for a run, reusing a warm base clone.
     *
     * @param  string|null  $cloneUrl  Authenticated clone URL for private repos;
     *                                 defaults to the repository's stored url.
     * @return string Absolute path to the worktree, hard-reset + cleaned to $ref.
     */
    public function checkout(GitRepository $repo, string $ref, string $runId, ?string $cloneUrl = null): string
    {
        $this->trustWarmRepos();

        $base = $this->ensureBase($repo, $cloneUrl);

        return $this->makeWorktree($repo, $base, $ref, $runId);
    }

    /**
     * Warm clones live on a shared persistent volume that can be owned by a
     * different uid than the worker process (container re-creates, volume perms),
     * which makes git refuse every command with "detected dubious ownership".
     * Trust our own managed tree. safe.directory is deliberately ignored when set
     * via `-c`, so it must live in config; --system covers root AND the agent's
     * ephemeral HOME. Best-effort — never break the run over this.
     */
    private function trustWarmRepos(): void
    {
        Process::run(['git', 'config', '--system', '--replace-all', 'safe.directory', '*']);
    }

    /**
     * Ensure a persistent base clone exists: clone ONCE, otherwise fetch.
     */
    private function ensureBase(GitRepository $repo, ?string $cloneUrl): string
    {
        $base = $this->basePath($repo);
        if (! is_dir(dirname($base))) {
            mkdir(dirname($base), 0700, true);
        }

        $this->withLock($base, function () use ($base, $repo, $cloneUrl): void {
            if (is_dir($base.'/.git')) {
                $this->git(['-C', $base, 'fetch', '--prune', 'origin']);

                return;
            }

            $url = $cloneUrl ?: (string) $repo->url;
            if ($url === '') {
                throw new RuntimeException("GitRepository {$repo->id} has no clone url.");
            }
            $this->git(['clone', $url, $base]);
        });

        return $base;
    }

    private function makeWorktree(GitRepository $repo, string $base, string $ref, string $runId): string
    {
        $branch = 'fleetq/run-'.substr($runId, 0, 8);
        $path = $this->worktreeDir($repo).'/'.substr($runId, 0, 8).'-'.Str::slug($branch);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }

        // Reuse-safe: drop any worktree already registered at this slot (e.g. a
        // re-run of the same id) so it starts pristine. Best-effort.
        Process::run(['git', '-C', $base, 'worktree', 'remove', '--force', $path]);
        Process::run(['git', '-C', $base, 'worktree', 'prune']);

        // --force + -B so the slot is deterministic even if it pre-exists.
        $this->git(['-C', $base, 'worktree', 'add', '--force', '-B', $branch, $path, $ref]);
        // Belt-and-suspenders: a pristine tree regardless of any prior state.
        $this->git(['-C', $path, 'reset', '--hard', $ref]);
        $this->git(['-C', $path, 'clean', '-fdx']);

        return $path;
    }

    public function release(GitRepository $repo, string $worktreePath): void
    {
        $base = $this->basePath($repo);
        if (is_dir($base.'/.git')) {
            // Best-effort: a failed remove must not break the caller's teardown.
            Process::run(['git', '-C', $base, 'worktree', 'remove', '--force', $worktreePath]);
        }
    }

    /**
     * GC: keep the $keep most-recently-modified worktrees, remove the rest.
     */
    public function prune(GitRepository $repo, int $keep = 5): int
    {
        $dir = $this->worktreeDir($repo);
        if (! is_dir($dir)) {
            return 0;
        }
        $base = $this->basePath($repo);

        $entries = array_values(array_filter(glob($dir.'/*') ?: [], 'is_dir'));
        usort($entries, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        $removed = 0;
        foreach (array_slice($entries, max(0, $keep)) as $stale) {
            Process::run(['git', '-C', $base, 'worktree', 'remove', '--force', $stale]);
            $removed++;
        }

        return $removed;
    }

    private function basePath(GitRepository $repo): string
    {
        return $this->baseDir().'/'.$repo->team_id.'/'.$repo->id;
    }

    private function worktreeDir(GitRepository $repo): string
    {
        return $this->baseDir().'/'.$repo->team_id.'/'.$repo->id.'.worktrees';
    }

    private function baseDir(): string
    {
        return rtrim((string) config('experiments.warm_build.base_dir', storage_path('app/warm-repos')), '/');
    }

    /**
     * @param  list<string>  $args
     */
    private function git(array $args): void
    {
        $result = Process::run(array_merge(['git'], $args));
        if (! $result->successful()) {
            throw new RuntimeException('git '.implode(' ', $args).' failed: '.trim($result->errorOutput()));
        }
    }

    /**
     * Serialize base-repo mutation (clone/fetch) across concurrent runs so two
     * runs on the same repo don't race the base. Falls back to unlocked rather
     * than failing the run if the lock can't be acquired.
     */
    private function withLock(string $base, callable $fn): void
    {
        $fh = @fopen($base.'.lock', 'c');
        if ($fh === false) {
            $fn();

            return;
        }
        try {
            flock($fh, LOCK_EX);
            $fn();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
