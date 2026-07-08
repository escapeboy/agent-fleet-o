<?php

namespace App\Domain\Experiment\Services;

use App\Domain\Experiment\DTOs\WarmBuildCandidate;

/**
 * Deterministic best-of-N selection for warm-build candidates (Shepherd borrow
 * #1). No LLM judge — a fixed, explainable ordering so the same candidates
 * always yield the same winner:
 *
 *   1. has changes            (a no-op candidate can never win)
 *   2. within writable-roots  (a scope-violating change loses to a compliant one)
 *   3. verify result          (pass > unknown > fail, when a verify cmd is set)
 *   4. smallest change        (fewer files, then fewer lines — "smallest correct change")
 *
 * pick() returns the best candidate, or null when none produced a usable change.
 */
class WarmBuildCandidateScorer
{
    /**
     * Comparable score tuple, higher-is-better, compared lexicographically.
     *
     * @return list<int>
     */
    public function score(WarmBuildCandidate $c): array
    {
        $changeset = $c->changeset;
        $fileCount = $changeset !== null ? count($changeset->files) : 0;
        $lines = $changeset !== null ? $changeset->added + $changeset->removed : 0;

        return [
            $c->hasChanges() ? 1 : 0,
            $c->withinRoots ? 1 : 0,
            $c->verify,
            -$fileCount,   // negated so "fewer" ranks higher
            -$lines,
        ];
    }

    /**
     * @param  list<WarmBuildCandidate>  $candidates
     */
    public function pick(array $candidates): ?WarmBuildCandidate
    {
        $viable = array_values(array_filter($candidates, static fn (WarmBuildCandidate $c): bool => $c->hasChanges()));
        if ($viable === []) {
            return null;
        }

        $winner = $viable[0];
        $best = $this->score($winner);
        foreach (array_slice($viable, 1) as $candidate) {
            $tuple = $this->score($candidate);
            if ($this->greater($tuple, $best)) {
                $winner = $candidate;
                $best = $tuple;
            }
        }

        return $winner;
    }

    /**
     * @param  list<int>  $a
     * @param  list<int>  $b
     */
    private function greater(array $a, array $b): bool
    {
        foreach ($a as $i => $value) {
            if ($value !== $b[$i]) {
                return $value > $b[$i];
            }
        }

        return false;
    }
}
