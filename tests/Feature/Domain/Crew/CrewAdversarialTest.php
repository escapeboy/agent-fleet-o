<?php

namespace Tests\Feature\Domain\Crew;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Actions\BuildAdversarialRoundTasksAction;
use App\Domain\Crew\Actions\SendAgentMessageAction;
use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Enums\CrewProcessType;
use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewTaskExecution;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrewAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Agent $coordinator;

    private Agent $workerA;

    private Agent $workerB;

    private CrewExecution $execution;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Adversarial Test Team',
            'slug' => 'adversarial-test',
            'owner_id' => $user->id,
            'settings' => [],
        ]);

        $this->coordinator = Agent::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Coordinator',
            'role' => 'Debate Moderator',
            'goal' => 'Moderate the debate and synthesize conclusions',
        ]);

        $this->workerA = Agent::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Analyst Alpha',
            'role' => 'Senior Analyst',
        ]);

        $this->workerB = Agent::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Analyst Beta',
            'role' => 'Senior Analyst',
        ]);

        $crew = Crew::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $user->id,
            'coordinator_agent_id' => $this->coordinator->id,
            'qa_agent_id' => $this->workerA->id,
        ]);

        $this->execution = CrewExecution::create([
            'team_id' => $this->team->id,
            'crew_id' => $crew->id,
            'goal' => 'Determine root cause of performance regression',
            'status' => CrewExecutionStatus::Executing,
            'config_snapshot' => [
                'process_type' => CrewProcessType::Adversarial->value,
                'coordinator' => [
                    'id' => $this->coordinator->id,
                    'name' => $this->coordinator->name,
                ],
                'workers' => [
                    ['id' => $this->workerA->id, 'name' => 'Analyst Alpha', 'role' => 'Senior Analyst', 'goal' => 'Investigate', 'skills' => []],
                    ['id' => $this->workerB->id, 'name' => 'Analyst Beta', 'role' => 'Senior Analyst', 'goal' => 'Investigate', 'skills' => []],
                ],
                'max_task_iterations' => 3,
                'adversarial_rounds' => 2,
            ],
            'total_cost_credits' => 0,
            'coordinator_iterations' => 0,
            'delegation_depth' => 0,
        ]);
    }

    private function createRound1Task(Agent $agent, int $sortOrder = 0): CrewTaskExecution
    {
        return CrewTaskExecution::create([
            'crew_execution_id' => $this->execution->id,
            'agent_id' => $agent->id,
            'title' => "Round 1: Hypothesis — {$agent->name}",
            'description' => 'Investigate the hypothesis',
            'status' => CrewTaskStatus::Validated,
            'input_context' => ['debate_round' => 1, 'assigned_to' => $agent->name],
            'output' => ['hypothesis' => "Hypothesis from {$agent->name}", 'evidence' => 'Evidence A'],
            'qa_score' => 0.85,
            'depends_on' => [],
            'attempt_number' => 1,
            'max_attempts' => 3,
            'sort_order' => $sortOrder,
            'completed_at' => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // CrewProcessType enum
    // -------------------------------------------------------------------------

    public function test_adversarial_process_type_has_correct_value(): void
    {
        $this->assertEquals('adversarial', CrewProcessType::Adversarial->value);
    }

    public function test_adversarial_process_type_has_correct_label(): void
    {
        $this->assertEquals('Adversarial Debate', CrewProcessType::Adversarial->label());
    }

    public function test_adversarial_process_type_from_string(): void
    {
        $type = CrewProcessType::from('adversarial');
        $this->assertEquals(CrewProcessType::Adversarial, $type);
    }

    // -------------------------------------------------------------------------
    // BuildAdversarialRoundTasksAction
    // -------------------------------------------------------------------------

    public function test_build_adversarial_round_tasks_creates_one_task_per_worker(): void
    {
        // Create round 1 tasks as context
        $taskA = $this->createRound1Task($this->workerA, 0);
        $taskB = $this->createRound1Task($this->workerB, 1);

        // Seed round-1 findings messages
        (new SendAgentMessageAction)->execute(
            $this->execution, 'finding', json_encode(['hypothesis' => 'Hypothesis from Alpha']),
            sender: $this->workerA, round: 1,
        );
        (new SendAgentMessageAction)->execute(
            $this->execution, 'finding', json_encode(['hypothesis' => 'Hypothesis from Beta']),
            sender: $this->workerB, round: 1,
        );

        $action = new BuildAdversarialRoundTasksAction;
        $round2Tasks = $action->execute($this->execution, 2, [$taskA, $taskB]);

        // Should create one task per worker
        $this->assertCount(2, $round2Tasks);
    }

    public function test_build_adversarial_round_tasks_sets_debate_round_in_context(): void
    {
        $taskA = $this->createRound1Task($this->workerA, 0);
        $taskB = $this->createRound1Task($this->workerB, 1);

        $action = new BuildAdversarialRoundTasksAction;
        $round2Tasks = $action->execute($this->execution, 2, [$taskA, $taskB]);

        foreach ($round2Tasks as $task) {
            $this->assertEquals(2, $task->input_context['debate_round']);
        }
    }

    public function test_build_adversarial_round_tasks_includes_other_agents_findings_in_description(): void
    {
        $taskA = $this->createRound1Task($this->workerA, 0);
        $taskB = $this->createRound1Task($this->workerB, 1);

        // Seed findings
        (new SendAgentMessageAction)->execute(
            $this->execution, 'finding', 'Alpha finding content',
            sender: $this->workerA, round: 1,
        );

        $action = new BuildAdversarialRoundTasksAction;
        $round2Tasks = $action->execute($this->execution, 2, [$taskA, $taskB]);

        // Beta's task description should contain Alpha's finding (cross-agent context)
        $betaTask = collect($round2Tasks)->first(fn ($t) => $t->agent_id === $this->workerB->id);
        $this->assertNotNull($betaTask);
        $this->assertStringContainsString('Alpha finding content', $betaTask->description);
    }

    public function test_build_adversarial_round_tasks_creates_pending_tasks(): void
    {
        $taskA = $this->createRound1Task($this->workerA, 0);
        $taskB = $this->createRound1Task($this->workerB, 1);

        $action = new BuildAdversarialRoundTasksAction;
        $round2Tasks = $action->execute($this->execution, 2, [$taskA, $taskB]);

        foreach ($round2Tasks as $task) {
            $this->assertEquals(CrewTaskStatus::Pending, $task->status);
        }
    }

    public function test_build_adversarial_round_tasks_has_prefixed_titles(): void
    {
        $taskA = $this->createRound1Task($this->workerA, 0);
        $taskB = $this->createRound1Task($this->workerB, 1);

        $action = new BuildAdversarialRoundTasksAction;
        $round2Tasks = $action->execute($this->execution, 2, [$taskA, $taskB]);

        foreach ($round2Tasks as $task) {
            $this->assertStringStartsWith('Round 2: Challenge & defend', $task->title);
        }
    }
}
