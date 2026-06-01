<?php

namespace Tests\Feature\Orchestration;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewMember;
use App\Domain\Orchestration\Exceptions\CostGateExceededException;
use App\Domain\Orchestration\Services\OrchestrationCostEstimator;
use App\Domain\Orchestration\Services\OrchestrationCostGate;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrchestrationCostGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_estimate_scales_with_agents_and_iterations(): void
    {
        $team = Team::factory()->create();
        $coordinator = Agent::factory()->create(['team_id' => $team->id]);
        $qa = Agent::factory()->create(['team_id' => $team->id]);
        // Default factory crew: 0 workers → agentCount 2, max_task_iterations 3 → factor 2.
        $crew = Crew::factory()->create([
            'team_id' => $team->id,
            'max_task_iterations' => 3,
            'coordinator_agent_id' => $coordinator->id,
            'qa_agent_id' => $qa->id,
        ]);

        $estimator = app(OrchestrationCostEstimator::class);
        $this->assertSame(2 * 11 * 2, $estimator->estimateCrew($crew)); // 44

        CrewMember::factory()->count(2)->create(['crew_id' => $crew->id]); // default role = Worker
        $this->assertSame(4 * 11 * 2, $estimator->estimateCrew($crew->fresh())); // 88
    }

    public function test_gate_is_noop_when_disabled(): void
    {
        config(['orchestration.cost_gate.enabled' => false]);
        $gate = app(OrchestrationCostGate::class);

        $this->assertFalse($gate->requiresConfirmation(1_000_000, null));
        $gate->assertConfirmed(1_000_000, false, null); // must not throw
    }

    public function test_gate_trips_over_threshold_when_unconfirmed(): void
    {
        config(['orchestration.cost_gate.enabled' => true, 'orchestration.cost_gate.threshold_credits' => 100]);
        $gate = app(OrchestrationCostGate::class);

        $this->assertTrue($gate->requiresConfirmation(150, null));

        $this->expectException(CostGateExceededException::class);
        $gate->assertConfirmed(150, false, null);
    }

    public function test_confirmation_bypasses_gate(): void
    {
        config(['orchestration.cost_gate.enabled' => true, 'orchestration.cost_gate.threshold_credits' => 100]);
        $gate = app(OrchestrationCostGate::class);

        $gate->assertConfirmed(150, true, null); // confirmed → no throw
        $this->expectNotToPerformAssertions();
    }

    public function test_under_threshold_does_not_trip(): void
    {
        config(['orchestration.cost_gate.enabled' => true, 'orchestration.cost_gate.threshold_credits' => 100]);
        $gate = app(OrchestrationCostGate::class);

        $this->assertFalse($gate->requiresConfirmation(50, null));
        $gate->assertConfirmed(50, false, null);
    }

    public function test_team_setting_overrides_threshold(): void
    {
        config(['orchestration.cost_gate.enabled' => true, 'orchestration.cost_gate.threshold_credits' => 100]);
        $team = Team::factory()->create(['settings' => ['cost_gate_threshold_credits' => 1000]]);
        $gate = app(OrchestrationCostGate::class);

        $this->assertSame(1000, $gate->thresholdFor($team));
        $this->assertFalse($gate->requiresConfirmation(150, $team)); // under team override
    }
}
