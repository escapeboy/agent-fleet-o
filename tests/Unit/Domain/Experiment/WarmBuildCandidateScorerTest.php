<?php

namespace Tests\Unit\Domain\Experiment;

use App\Domain\Experiment\DTOs\WarmBuildCandidate;
use App\Domain\Experiment\Services\WarmBuildCandidatePolicy;
use App\Domain\Experiment\Services\WarmBuildCandidateScorer;
use App\Domain\GitRepository\DTOs\WarmBuildChangeset;
use App\Domain\GitRepository\Models\GitRepository;
use Tests\TestCase;

class WarmBuildCandidateScorerTest extends TestCase
{
    private function scorer(): WarmBuildCandidateScorer
    {
        return new WarmBuildCandidateScorer;
    }

    /**
     * @param  list<string>  $files
     */
    private function cand(
        int $i,
        array $files,
        bool $withinRoots = true,
        int $verify = WarmBuildCandidate::VERIFY_UNKNOWN,
        int $added = 5,
        int $removed = 0,
        bool $failed = false,
    ): WarmBuildCandidate {
        $changeset = $failed
            ? null
            : new WarmBuildChangeset(files: $files, added: $added, removed: $removed, headSha: $files === [] ? '' : 'sha'.$i);

        return new WarmBuildCandidate(
            index: $i,
            runId: 'exp-c'.$i,
            worktree: $failed ? null : '/wt/'.$i,
            changeset: $changeset,
            withinRoots: $withinRoots,
            verify: $verify,
            failed: $failed,
        );
    }

    // ---- pick ----

    public function test_pick_empty_returns_null(): void
    {
        $this->assertNull($this->scorer()->pick([]));
    }

    public function test_pick_all_no_change_returns_null(): void
    {
        $this->assertNull($this->scorer()->pick([
            $this->cand(1, []),
            $this->cand(2, [], failed: true),
        ]));
    }

    public function test_has_changes_beats_no_change(): void
    {
        $winner = $this->scorer()->pick([
            $this->cand(1, []),                 // no change
            $this->cand(2, ['app/Foo.php']),    // change
        ]);
        $this->assertSame(2, $winner?->index);
    }

    public function test_within_roots_beats_violation_same_diff(): void
    {
        $winner = $this->scorer()->pick([
            $this->cand(1, ['database/migrations/x.php'], withinRoots: false),
            $this->cand(2, ['app/Foo.php'], withinRoots: true),
        ]);
        $this->assertSame(2, $winner?->index);
    }

    public function test_verify_pass_beats_unknown_beats_fail(): void
    {
        $winner = $this->scorer()->pick([
            $this->cand(1, ['app/A.php'], verify: WarmBuildCandidate::VERIFY_FAIL),
            $this->cand(2, ['app/B.php'], verify: WarmBuildCandidate::VERIFY_UNKNOWN),
            $this->cand(3, ['app/C.php'], verify: WarmBuildCandidate::VERIFY_PASS),
        ]);
        $this->assertSame(3, $winner?->index);
    }

    public function test_tie_breaks_to_smaller_change(): void
    {
        $winner = $this->scorer()->pick([
            $this->cand(1, ['app/A.php', 'app/B.php'], added: 40),  // 2 files
            $this->cand(2, ['app/A.php'], added: 10),               // 1 file — smaller
        ]);
        $this->assertSame(2, $winner?->index);
    }

    public function test_fewer_lines_breaks_tie_on_equal_file_count(): void
    {
        $winner = $this->scorer()->pick([
            $this->cand(1, ['app/A.php'], added: 80),
            $this->cand(2, ['app/B.php'], added: 3),  // smaller diff
        ]);
        $this->assertSame(2, $winner?->index);
    }

    public function test_all_violate_roots_returns_best_scoring_flagged(): void
    {
        // Every candidate is out of roots; pick still returns the best-scoring one,
        // flagged (withinRoots=false) so the PR body can warn the reviewer.
        $winner = $this->scorer()->pick([
            $this->cand(1, ['database/migrations/a.php', 'database/migrations/b.php'], withinRoots: false, added: 50),
            $this->cand(2, ['database/migrations/a.php'], withinRoots: false, added: 10),
        ]);
        $this->assertSame(2, $winner?->index);
        $this->assertFalse($winner->withinRoots);
    }

    // ---- WarmBuildCandidatePolicy::count ----

    public function test_count_default_is_one(): void
    {
        config(['experiments.warm_build.candidates' => 1, 'experiments.warm_build.candidates_max' => 3]);
        $this->assertSame(1, (new WarmBuildCandidatePolicy)->count(new GitRepository));
    }

    public function test_count_reads_config(): void
    {
        config(['experiments.warm_build.candidates' => 3, 'experiments.warm_build.candidates_max' => 3]);
        $this->assertSame(3, (new WarmBuildCandidatePolicy)->count(new GitRepository));
    }

    public function test_count_clamped_to_max(): void
    {
        config(['experiments.warm_build.candidates' => 99, 'experiments.warm_build.candidates_max' => 3]);
        $this->assertSame(3, (new WarmBuildCandidatePolicy)->count(new GitRepository));
    }

    public function test_count_floored_to_one(): void
    {
        config(['experiments.warm_build.candidates' => 0, 'experiments.warm_build.candidates_max' => 3]);
        $this->assertSame(1, (new WarmBuildCandidatePolicy)->count(new GitRepository));
    }

    public function test_count_repo_override_wins(): void
    {
        config(['experiments.warm_build.candidates' => 1, 'experiments.warm_build.candidates_max' => 3]);
        $repo = new GitRepository;
        $repo->config = ['warm_build_candidates' => 2];
        $this->assertSame(2, (new WarmBuildCandidatePolicy)->count($repo));
    }
}
