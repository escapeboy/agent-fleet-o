<?php

namespace Tests\Unit\Domain\Agent\Actions;

use App\Domain\Agent\Actions\ProcessAgentHandoffAction;
use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Experiment\Pipeline\ExecutePlaybookStepJob;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProcessAgentHandoffActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_handoff_swaps_agent_and_dispatches_job(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $sourceAgent = Agent::factory()->create(['team_id' => $team->id, 'status' => AgentStatus::Active]);
        $targetAgent = Agent::factory()->create(['team_id' => $team->id, 'status' => AgentStatus::Active]);
        $experiment = Experiment::factory()->create(['team_id' => $team->id]);
        $step = PlaybookStep::create([
            'experiment_id' => $experiment->id,
            'agent_id' => $sourceAgent->id,
            'order' => 0,
            'status' => 'running',
        ]);

        $action = new ProcessAgentHandoffAction;
        $action->execute(
            experiment: $experiment,
            step: $step,
            handoffDirective: [
                'target_agent_id' => $targetAgent->id,
                'reason' => 'Needs data analysis',
                'context' => ['partial' => 'result'],
            ],
        );

        $step->refresh();
        $this->assertEquals($targetAgent->id, $step->agent_id);
        $this->assertEquals('pending', $step->status);
        $this->assertNotEmpty($step->checkpoint_data['handoff_chain']);
        $this->assertEquals($sourceAgent->id, $step->checkpoint_data['original_agent_id']);

        Queue::assertPushed(ExecutePlaybookStepJob::class, function ($job) use ($step) {
            return $job->stepId === $step->id;
        });
    }

    public function test_handoff_enforces_depth_limit(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $sourceAgent = Agent::factory()->create(['team_id' => $team->id, 'status' => AgentStatus::Active]);
        $targetAgent = Agent::factory()->create(['team_id' => $team->id, 'status' => AgentStatus::Active]);
        $experiment = Experiment::factory()->create([
            'team_id' => $team->id,
            'constraints' => ['max_handoffs_per_step' => 2],
        ]);
        $step = PlaybookStep::create([
            'experiment_id' => $experiment->id,
            'agent_id' => $sourceAgent->id,
            'order' => 0,
            'status' => 'running',
            'checkpoint_data' => [
                'handoff_chain' => [
                    ['from_agent_id' => 'a1', 'to_agent_id' => 'a2', 'reason' => 'test', 'timestamp' => now()->toIso8601String()],
                    ['from_agent_id' => 'a2', 'to_agent_id' => 'a3', 'reason' => 'test', 'timestamp' => now()->toIso8601String()],
                ],
            ],
        ]);

        $action = new ProcessAgentHandoffAction;
        $action->execute(
            experiment: $experiment,
            step: $step,
            handoffDirective: [
                'target_agent_id' => $targetAgent->id,
                'reason' => 'Another handoff',
            ],
        );

        $step->refresh();
        $this->assertEquals('completed', $step->status);
        $this->assertTrue($step->output['_handoff_limit_reached']);

        Queue::assertNotPushed(ExecutePlaybookStepJob::class);
    }

    public function test_handoff_fails_gracefully_for_inactive_target(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $sourceAgent = Agent::factory()->create(['team_id' => $team->id, 'status' => AgentStatus::Active]);
        $targetAgent = Agent::factory()->create(['team_id' => $team->id, 'status' => AgentStatus::Disabled]);
        $experiment = Experiment::factory()->create(['team_id' => $team->id]);
        $step = PlaybookStep::create([
            'experiment_id' => $experiment->id,
            'agent_id' => $sourceAgent->id,
            'order' => 0,
            'status' => 'running',
        ]);

        $action = new ProcessAgentHandoffAction;
        $action->execute(
            experiment: $experiment,
            step: $step,
            handoffDirective: [
                'target_agent_id' => $targetAgent->id,
                'reason' => 'Should fail',
            ],
        );

        $step->refresh();
        $this->assertEquals('completed', $step->status);
        $this->assertTrue($step->output['_handoff_failed']);

        Queue::assertNotPushed(ExecutePlaybookStepJob::class);
    }
}
