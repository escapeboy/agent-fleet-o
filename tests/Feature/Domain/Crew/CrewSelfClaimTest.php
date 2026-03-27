<?php

namespace Tests\Feature\Domain\Crew;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Actions\ClaimNextTaskAction;
use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Enums\CrewProcessType;
use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Models\Crew; // needed for factory
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewTaskExecution;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrewSelfClaimTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Agent $agentA;

    private Agent $agentB;

    private CrewExecution $execution;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Self-Claim Test Team',
            'slug' => 'self-claim-test',
            'owner_id' => $user->id,
            'settings' => [],
        ]);

        $this->agentA = Agent::factory()->create(['team_id' => $this->team->id, 'name' => 'Worker Alpha']);
        $this->agentB = Agent::factory()->create(['team_id' => $this->team->id, 'name' => 'Worker Beta']);

        $crew = Crew::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $user->id,
            'coordinator_agent_id' => $this->agentA->id,
            'qa_agent_id' => $this->agentB->id,
        ]);

        $this->execution = CrewExecution::create([
            'team_id' => $this->team->id,
            'crew_id' => $crew->id,
            'goal' => 'Complete multiple tasks efficiently',
            'status' => CrewExecutionStatus::Executing,
            'config_snapshot' => [
                'process_type' => CrewProcessType::SelfClaim->value,
                'coordinator' => ['id' => $this->agentA->id, 'name' => 'Worker Alpha'],
                'workers' => [
                    ['id' => $this->agentA->id, 'name' => 'Worker Alpha', 'role' => 'Worker'],
                    ['id' => $this->agentB->id, 'name' => 'Worker Beta', 'role' => 'Worker'],
                ],
            ],
            'total_cost_credits' => 0,
            'coordinator_iterations' => 0,
            'delegation_depth' => 0,
        ]);
    }

    private function createPendingTask(int $sortOrder = 0): CrewTaskExecution
    {
        return CrewTaskExecution::create([
            'crew_execution_id' => $this->execution->id,
            'title' => "Task {$sortOrder}",
            'description' => "Description {$sortOrder}",
            'status' => CrewTaskStatus::Pending,
            'input_context' => [],
            'depends_on' => [],
            'attempt_number' => 1,
            'max_attempts' => 3,
            'sort_order' => $sortOrder,
        ]);
    }

    // -------------------------------------------------------------------------
    // ClaimNextTaskAction — basic claim
    // -------------------------------------------------------------------------

    public function test_claim_next_task_action_claims_first_pending_task(): void
    {
        $task0 = $this->createPendingTask(0);
        $task1 = $this->createPendingTask(1);

        $action = new ClaimNextTaskAction;
        $claimed = $action->execute($this->execution, $this->agentA);

        $this->assertNotNull($claimed);
        $this->assertEquals($task0->id, $claimed->id);
        $this->assertEquals(CrewTaskStatus::Assigned, $claimed->status);
        $this->assertEquals($this->agentA->id, $claimed->agent_id);
        $this->assertNotNull($claimed->claimed_at);
    }

    public function test_claim_next_task_action_returns_null_when_no_pending_tasks(): void
    {
        // No tasks created
        $action = new ClaimNextTaskAction;
        $claimed = $action->execute($this->execution, $this->agentA);

        $this->assertNull($claimed);
    }

    public function test_claim_next_task_action_respects_sort_order(): void
    {
        // Create tasks out of order
        $task2 = $this->createPendingTask(2);
        $task0 = $this->createPendingTask(0);
        $task1 = $this->createPendingTask(1);

        $action = new ClaimNextTaskAction;

        $first = $action->execute($this->execution, $this->agentA);
        $this->assertEquals($task0->id, $first->id);

        $second = $action->execute($this->execution, $this->agentB);
        $this->assertEquals($task1->id, $second->id);
    }

    public function test_claimed_task_is_no_longer_pending(): void
    {
        $this->createPendingTask(0);

        $action = new ClaimNextTaskAction;
        $action->execute($this->execution, $this->agentA);

        // The same agent cannot claim the same task again
        $secondClaim = $action->execute($this->execution, $this->agentA);
        $this->assertNull($secondClaim);
    }

    public function test_two_agents_claim_different_tasks(): void
    {
        $this->createPendingTask(0);
        $this->createPendingTask(1);

        $action = new ClaimNextTaskAction;

        $claimedA = $action->execute($this->execution, $this->agentA);
        $claimedB = $action->execute($this->execution, $this->agentB);

        $this->assertNotNull($claimedA);
        $this->assertNotNull($claimedB);
        $this->assertNotEquals($claimedA->id, $claimedB->id);
    }

    // -------------------------------------------------------------------------
    // CrewProcessType enum
    // -------------------------------------------------------------------------

    public function test_self_claim_process_type_has_correct_label(): void
    {
        $this->assertEquals('Self-Claim Pool', CrewProcessType::SelfClaim->label());
    }

    public function test_self_claim_process_type_has_description(): void
    {
        $this->assertNotEmpty(CrewProcessType::SelfClaim->description());
    }

    public function test_self_claim_process_type_from_string(): void
    {
        $type = CrewProcessType::from('self_claim');
        $this->assertEquals(CrewProcessType::SelfClaim, $type);
    }
}
