<?php

namespace Tests\Feature\Domain\Experiment;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Pipeline\RunScoringStage;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\Exceptions\VpsLocalAgentException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * A transient VPS concurrency-cap failure is a shared-resource limit, not a
 * defect: a burst of experiments momentarily saturates the 2-slots-per-team cap.
 * The stage must wait its turn (re-dispatch after a backoff) rather than dying,
 * which is what was terminally failing every PriceX debug run under load.
 */
class StageTransientCapacityTest extends TestCase
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

    private function scoringExperiment(int $transientRetries = 0): Experiment
    {
        $exp = Experiment::factory()->create([
            'team_id' => $this->team->id,
            'track' => ExperimentTrack::Debug,
            'status' => ExperimentStatus::Scoring,
            'current_iteration' => 0,
            'title' => 'Fix bug: null deref',
            'thesis' => 'It crashes',
        ]);

        // iteration must match the experiment so findOrCreateStage reuses this row.
        ExperimentStage::factory()->create([
            'team_id' => $this->team->id,
            'experiment_id' => $exp->id,
            'stage' => StageType::Scoring,
            'iteration' => 0,
            'status' => StageStatus::Pending,
            'output_snapshot' => $transientRetries > 0 ? ['_transient_retries' => $transientRetries] : [],
        ]);

        return $exp;
    }

    private function bindGatewayThrowing(\Throwable $e): void
    {
        $gw = Mockery::mock(AiGatewayInterface::class);
        $gw->shouldReceive('complete')->andThrow($e);
        $this->app->instance(AiGatewayInterface::class, $gw);
    }

    private function scoringStage(Experiment $exp): ExperimentStage
    {
        return ExperimentStage::withoutGlobalScopes()
            ->where('experiment_id', $exp->id)
            ->where('stage', StageType::Scoring)
            ->latest()->firstOrFail();
    }

    public function test_transient_cap_re_dispatches_instead_of_failing(): void
    {
        Queue::fake();
        $this->bindGatewayThrowing(VpsLocalAgentException::concurrencyCapReached(2));

        $exp = $this->scoringExperiment();
        (new RunScoringStage($exp->id, $exp->team_id))->handle();

        $exp->refresh();
        $this->assertSame(ExperimentStatus::Scoring, $exp->status, 'transient cap must not fail the run');

        Queue::assertPushed(RunScoringStage::class);

        $stage = $this->scoringStage($exp);
        $this->assertSame(StageStatus::Pending, $stage->status);
        $this->assertSame(1, $stage->output_snapshot['_transient_retries'] ?? null);
    }

    public function test_transient_cap_fails_after_budget_exhausted(): void
    {
        Queue::fake();
        config(['experiments.transient_capacity.max_retries' => 2]);
        $this->bindGatewayThrowing(VpsLocalAgentException::concurrencyCapReached(2));

        $exp = $this->scoringExperiment(transientRetries: 2);

        try {
            (new RunScoringStage($exp->id, $exp->team_id))->handle();
            $this->fail('expected the cap exception to propagate once the budget is exhausted');
        } catch (VpsLocalAgentException $e) {
            // expected — falls through to the normal failure path
        }

        Queue::assertNotPushed(RunScoringStage::class);
        $this->assertSame(StageStatus::Failed, $this->scoringStage($exp)->status);
    }

    public function test_genuine_failure_is_not_treated_as_transient(): void
    {
        Queue::fake();
        $this->bindGatewayThrowing(new \RuntimeException('malformed LLM JSON'));

        $exp = $this->scoringExperiment();

        try {
            (new RunScoringStage($exp->id, $exp->team_id))->handle();
            $this->fail('expected a genuine failure to propagate');
        } catch (\RuntimeException $e) {
            // expected
        }

        Queue::assertNotPushed(RunScoringStage::class);
        $this->assertSame(StageStatus::Failed, $this->scoringStage($exp)->status);
    }
}
