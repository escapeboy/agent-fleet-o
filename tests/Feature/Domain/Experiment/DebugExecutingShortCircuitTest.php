<?php

namespace Tests\Feature\Domain\Experiment;

use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Pipeline\ExecuteOutbound;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A debug-track experiment delivers its fix as a pull request during the building
 * stage. There is nothing to "execute" afterwards; the stale workflow_graph such
 * experiments carry (a legacy Sentry Auto-Fix node) would fail in the executing
 * stage and wrongly mark the run execution_failed. It must short-circuit to
 * Completed instead — the draft PR + human GitHub review is the real gate.
 */
class DebugExecutingShortCircuitTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'T', 'slug' => 't-'.Str::random(6),
            'owner_id' => $user->id, 'settings' => [],
        ]);
    }

    private function experiment(ExperimentTrack $track): Experiment
    {
        return Experiment::factory()->create([
            'team_id' => $this->team->id,
            'track' => $track,
            'status' => ExperimentStatus::Approved,
            'constraints' => [],
            'title' => 'x',
        ]);
    }

    public function test_debug_experiment_short_circuits_executing_to_completed(): void
    {
        Queue::fake();
        $exp = $this->experiment(ExperimentTrack::Debug);

        app(TransitionExperimentAction::class)->execute(
            experiment: $exp,
            toState: ExperimentStatus::Executing,
            reason: 'approved',
        );

        $exp->refresh();
        $this->assertSame(ExperimentStatus::Completed, $exp->status);
        Queue::assertNotPushed(ExecuteOutbound::class);
    }

    public function test_non_debug_experiment_still_runs_executing_stage(): void
    {
        Queue::fake();
        $exp = $this->experiment(ExperimentTrack::Growth);

        app(TransitionExperimentAction::class)->execute(
            experiment: $exp,
            toState: ExperimentStatus::Executing,
            reason: 'approved',
        );

        $exp->refresh();
        $this->assertSame(ExperimentStatus::Executing, $exp->status);
        Queue::assertPushed(ExecuteOutbound::class);
    }
}
