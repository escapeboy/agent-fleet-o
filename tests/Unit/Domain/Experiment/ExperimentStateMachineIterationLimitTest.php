<?php

namespace Tests\Unit\Domain\Experiment;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\States\ExperimentStateMachine;
use App\Mcp\DeadlineContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Regression test for the off-by-one bug in `enforceIterationLimit`:
 * `RunEvaluationStage::handleIterate` increments `current_iteration` BEFORE
 * calling `transition->execute(toState: Iterating)`, so `current_iteration`
 * equal to `max_iterations` is the *last legitimate* cycle, not a violation.
 * The check therefore must use `>` not `>=`.
 */
class ExperimentStateMachineIterationLimitTest extends TestCase
{
    use RefreshDatabase;

    private ExperimentStateMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->machine = new ExperimentStateMachine;

        // Stub the deadline context so the test isn't fighting an unrelated guard.
        $this->app->instance(DeadlineContext::class, new class extends DeadlineContext
        {
            public function assertNotExpired(): void {}
        });
    }

    public function test_allows_transition_to_iterating_when_current_equals_max(): void
    {
        $experiment = Experiment::factory()->create([
            'status' => ExperimentStatus::Evaluating,
            'max_iterations' => 3,
            'current_iteration' => 3,
        ]);

        // Should NOT throw — current=3 with max=3 means "starting the 3rd cycle"
        // because handleIterate increments before transitioning.
        $this->machine->validate($experiment, ExperimentStatus::Iterating);

        $this->assertTrue(true);
    }

    public function test_allows_transition_when_current_below_max(): void
    {
        $experiment = Experiment::factory()->create([
            'status' => ExperimentStatus::Evaluating,
            'max_iterations' => 3,
            'current_iteration' => 1,
        ]);

        $this->machine->validate($experiment, ExperimentStatus::Iterating);

        $this->assertTrue(true);
    }

    public function test_throws_when_current_strictly_above_max(): void
    {
        $experiment = Experiment::factory()->create([
            'status' => ExperimentStatus::Evaluating,
            'max_iterations' => 3,
            'current_iteration' => 4,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Max iterations (3) exceeded');

        $this->machine->validate($experiment, ExperimentStatus::Iterating);
    }

    public function test_iteration_limit_does_not_apply_to_non_iterating_transitions(): void
    {
        $experiment = Experiment::factory()->create([
            'status' => ExperimentStatus::Evaluating,
            'max_iterations' => 3,
            'current_iteration' => 99,
        ]);

        // Killed/Completed transitions must remain reachable regardless of
        // iteration counter — that's how RunEvaluationStage::handleIterate
        // gracefully terminates an exhausted experiment.
        $this->machine->validate($experiment, ExperimentStatus::Killed);
        $this->machine->validate($experiment, ExperimentStatus::Completed);

        $this->assertTrue(true);
    }
}
