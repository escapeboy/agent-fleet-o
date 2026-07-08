<?php

namespace App\Domain\Experiment\Services;

use App\Domain\GitRepository\Models\GitRepository;

/**
 * Resolves how many best-of-N candidates a warm-build run should attempt
 * (Shepherd borrow #1). Default 1 = the legacy single-run behaviour. A per-repo
 * override (GitRepository.config['warm_build_candidates']) can raise it, but the
 * result is always clamped to [1, candidates_max] so a mis-set value can never
 * fan out unbounded on the capped credit.
 */
class WarmBuildCandidatePolicy
{
    public function count(GitRepository $repo): int
    {
        $max = max(1, (int) config('experiments.warm_build.candidates_max', 3));

        $override = $repo->config['warm_build_candidates'] ?? null;
        $requested = is_numeric($override)
            ? (int) $override
            : (int) config('experiments.warm_build.candidates', 1);

        return max(1, min($max, $requested));
    }
}
