<?php

namespace Tests\Feature\Crew;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Actions\ExecuteCrewAction;
use App\Domain\Crew\Models\Crew;
use App\Domain\Orchestration\Exceptions\CostGateExceededException;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The cost gate runs before coordinator/QA validation. A factory crew has no
 * coordinator, so once the gate passes the action throws InvalidArgumentException
 * — which lets us prove the gate let the call through without standing up a full crew.
 */
class ExecuteCrewCostGateTest extends TestCase
{
    use RefreshDatabase;

    private function activeCrew(): Crew
    {
        $team = Team::factory()->create();
        // Disabled coordinator → once the gate passes, the action throws
        // "Coordinator agent is not available" before the orchestrator runs.
        $coordinator = Agent::factory()->disabled()->create(['team_id' => $team->id]);
        $qa = Agent::factory()->create(['team_id' => $team->id]);

        return Crew::factory()->create([
            'team_id' => $team->id,
            'max_task_iterations' => 3,
            'coordinator_agent_id' => $coordinator->id,
            'qa_agent_id' => $qa->id,
        ]);
    }

    public function test_gate_blocks_when_enabled_over_threshold_unconfirmed(): void
    {
        config(['orchestration.cost_gate.enabled' => true, 'orchestration.cost_gate.threshold_credits' => 1]);
        $crew = $this->activeCrew();

        $this->expectException(CostGateExceededException::class);

        app(ExecuteCrewAction::class)->execute(
            crew: $crew,
            goal: 'do the thing',
            teamId: $crew->team_id,
            costConfirmed: false,
        );
    }

    public function test_confirmation_bypasses_gate(): void
    {
        config(['orchestration.cost_gate.enabled' => true, 'orchestration.cost_gate.threshold_credits' => 1]);
        $crew = $this->activeCrew();

        // Gate passes (confirmed) → falls through to coordinator validation.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Coordinator');

        app(ExecuteCrewAction::class)->execute(
            crew: $crew,
            goal: 'do the thing',
            teamId: $crew->team_id,
            costConfirmed: true,
        );
    }

    public function test_disabled_gate_is_noop(): void
    {
        config(['orchestration.cost_gate.enabled' => false]);
        $crew = $this->activeCrew();

        // No CostGateExceededException — proceeds to coordinator validation instead.
        $this->expectException(InvalidArgumentException::class);

        app(ExecuteCrewAction::class)->execute(
            crew: $crew,
            goal: 'do the thing',
            teamId: $crew->team_id,
            costConfirmed: false,
        );
    }
}
