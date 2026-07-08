<?php

namespace App\Domain\GitRepository\Services;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\GitRepository\DTOs\WritableRootsGrant;
use App\Domain\GitRepository\Models\GitRepository;

/**
 * Resolves the writable-roots grant (Shepherd borrow #3) for a warm-build run.
 *
 * Precedence, most specific last:
 *   config('experiments.warm_build.writable_roots')                 platform default
 *     → GitRepository.config['warm_build_writable_roots']           per-repo override
 *       → Experiment.constraints['warm_build_writable_roots']       per-task grant
 *
 * The per-task layer is the actual "signature is the permission surface" borrow:
 * a task declares the roots it needs up front. Deny globs ACCUMULATE across all
 * layers (a stricter layer can only add denials); allow-roots take the most
 * specific non-empty list.
 */
class WritableRootsPolicy
{
    public function resolve(GitRepository $repo, ?Experiment $experiment = null): WritableRootsGrant
    {
        $default = (array) config('experiments.warm_build.writable_roots', []);
        $repoOverride = $this->layer($repo->config['warm_build_writable_roots'] ?? null);
        $taskOverride = $this->layer($experiment?->constraints['warm_build_writable_roots'] ?? null);

        $denied = array_values(array_unique(array_merge(
            $this->stringList($default['denied_globs'] ?? []),
            $repoOverride['denied_globs'],
            $taskOverride['denied_globs'],
        )));

        // Most specific non-empty allow-list wins; empty everywhere = whole repo.
        $allowed = $taskOverride['allowed_roots']
            ?: ($repoOverride['allowed_roots']
                ?: $this->stringList($default['allowed_roots'] ?? []));

        return new WritableRootsGrant(allowedRoots: $allowed, deniedGlobs: $denied);
    }

    /**
     * Absolute writable subtrees under $worktree for the Landlock jail (#2).
     * An empty allow-list yields the whole worktree — the deny globs are then
     * enforced by ChangesetPolicyValidator at the application layer, since a
     * positive Landlock allow-list can't express "everything except migrations".
     *
     * @return list<string>
     */
    public function absoluteWritablePaths(WritableRootsGrant $grant, string $worktree): array
    {
        $worktree = rtrim(str_replace('\\', '/', $worktree), '/');

        if ($grant->allowedRoots === []) {
            return [$worktree];
        }

        $paths = [];
        foreach ($grant->allowedRoots as $root) {
            $paths[] = $worktree.'/'.trim(str_replace('\\', '/', $root), '/');
        }

        return $paths;
    }

    /**
     * @param  mixed  $raw
     * @return array{allowed_roots: list<string>, denied_globs: list<string>}
     */
    private function layer($raw): array
    {
        $raw = is_array($raw) ? $raw : [];

        return [
            'allowed_roots' => $this->stringList($raw['allowed_roots'] ?? []),
            'denied_globs' => $this->stringList($raw['denied_globs'] ?? []),
        ];
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    private function stringList($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($v): string => is_string($v) ? trim($v) : '', $raw),
            static fn (string $v): bool => $v !== '',
        ));
    }
}
