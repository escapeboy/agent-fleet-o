<?php

namespace Tests\Feature\Domain\Crew;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Actions\DecomposeGoalAction;
use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Exceptions\MaxDelegationDepthExceededException;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Experiment\Actions\PlanWithKnowledgeAction;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DecomposeGoalActionTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Agent $coordinator;

    private Agent $worker;

    private Crew $crew;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Decompose Test Team',
            'slug' => 'decompose-test',
            'owner_id' => $user->id,
            'settings' => [],
        ]);

        $this->coordinator = Agent::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Coordinator',
            'role' => 'Project Lead',
            'goal' => 'Coordinate the team to achieve the goal',
        ]);

        $this->worker = Agent::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Research Analyst',
            'role' => 'Analyst',
            'goal' => 'Research things',
        ]);

        $this->crew = Crew::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $user->id,
            'coordinator_agent_id' => $this->coordinator->id,
            'qa_agent_id' => $this->worker->id,
        ]);

        // Force the knowledge-enrichment fallback branch (catch -> empty context)
        // and keep prompts deterministic; never hit a real planner.
        $planner = $this->createMock(PlanWithKnowledgeAction::class);
        $planner->method('execute')->willThrowException(new \RuntimeException('no knowledge'));
        $this->instance(PlanWithKnowledgeAction::class, $planner);

        config(['crew.decision_log.enabled' => false]);
    }

    private function makeExecution(array $overrides = []): CrewExecution
    {
        return CrewExecution::create(array_merge([
            'team_id' => $this->team->id,
            'crew_id' => $this->crew->id,
            'goal' => 'Investigate the performance regression',
            'status' => CrewExecutionStatus::Executing,
            'delegation_depth' => 0,
            'config_snapshot' => [
                'process_type' => 'parallel',
                'coordinator' => [
                    'id' => $this->coordinator->id,
                    'name' => $this->coordinator->name,
                    'role' => $this->coordinator->role,
                    'goal' => $this->coordinator->goal,
                ],
                'workers' => [
                    [
                        'id' => $this->worker->id,
                        'name' => $this->worker->name,
                        'role' => $this->worker->role,
                        'goal' => $this->worker->goal,
                        'skills' => ['research'],
                    ],
                ],
            ],
        ], $overrides));
    }

    private function makeAction(string $responseContent, int $costCredits = 50): DecomposeGoalAction
    {
        $gateway = $this->createMock(AiGatewayInterface::class);
        $gateway->method('complete')->willReturn(new AiResponseDTO(
            content: $responseContent,
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 100, completionTokens: 200, costCredits: $costCredits),
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            latencyMs: 100,
        ));

        return new DecomposeGoalAction($gateway, $this->makeProviderResolver());
    }

    private function makeProviderResolver(): ProviderResolver
    {
        $providerResolver = $this->createMock(ProviderResolver::class);
        $providerResolver->method('resolve')->willReturn([
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5-20251001',
        ]);
        $providerResolver->method('forCrewRole')->willReturn([
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5-20251001',
        ]);

        return $providerResolver;
    }

    public function test_happy_path_creates_task_executions_with_dependencies_and_costs(): void
    {
        $execution = $this->makeExecution();

        $plan = json_encode([
            [
                'title' => 'Gather metrics',
                'description' => 'Collect performance data',
                'assigned_to' => 'research analyst', // case-insensitive worker match
                'dependencies' => [],
                'expected_output' => 'A metrics report',
            ],
            [
                'title' => 'Analyze findings',
                'description' => 'Coordinator reviews the data',
                'assigned_to' => 'self',
                'dependencies' => [0],
                'expected_output' => 'Root cause analysis',
            ],
        ]);

        $tasks = $this->makeAction($plan)->execute($execution);

        $this->assertCount(2, $tasks);

        $this->assertSame('Gather metrics', $tasks[0]->title);
        $this->assertSame($this->worker->id, $tasks[0]->agent_id);
        $this->assertSame(CrewTaskStatus::Pending, $tasks[0]->status);
        $this->assertSame([], $tasks[0]->depends_on ?? []);

        $this->assertSame($this->coordinator->id, $tasks[1]->agent_id);
        $this->assertSame(CrewTaskStatus::Blocked, $tasks[1]->status);
        // sort_order dependency indices are remapped to task UUIDs
        $this->assertSame([$tasks[0]->id], $tasks[1]->fresh()->depends_on);

        $execution->refresh();
        $this->assertCount(2, $execution->task_plan);
        $this->assertSame(50, (int) $execution->total_cost_credits);
    }

    public function test_strips_markdown_fences_and_unwraps_tasks_key(): void
    {
        $execution = $this->makeExecution();

        $dirty = "```json\n".json_encode([
            'tasks' => [
                [
                    'title' => 'Fenced task',
                    'description' => 'Came wrapped in markdown',
                    'assigned_to' => 'self',
                    'expected_output' => 'Output',
                ],
            ],
        ])."\n```";

        $tasks = $this->makeAction($dirty)->execute($execution);

        $this->assertCount(1, $tasks);
        $this->assertSame('Fenced task', $tasks[0]->title);
        $this->assertSame(CrewTaskStatus::Pending, $tasks[0]->status);
    }

    public function test_malformed_llm_output_throws_runtime_exception(): void
    {
        $execution = $this->makeExecution();

        $action = $this->makeAction("Sure! Here is the plan you asked for:\n1. Do the thing");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Coordinator did not produce a valid JSON task plan.');

        $action->execute($execution);

        $this->assertSame(0, $execution->taskExecutions()->count());
    }

    public function test_invalid_skip_condition_is_stripped_while_valid_one_is_kept(): void
    {
        $execution = $this->makeExecution();

        $plan = json_encode([
            [
                'title' => 'Task with bad condition',
                'description' => '',
                'assigned_to' => 'self',
                'expected_output' => 'x',
                'skip_condition' => ['field' => 'output.status', 'operator' => 'EXEC'],
            ],
            [
                'title' => 'Task with good condition',
                'description' => '',
                'assigned_to' => 'self',
                'dependencies' => [0],
                'expected_output' => 'y',
                'skip_condition' => ['field' => 'output.status', 'operator' => '==', 'value' => 'done'],
            ],
        ]);

        $tasks = $this->makeAction($plan)->execute($execution);

        $this->assertNull($tasks[0]->skip_condition);
        $this->assertSame(
            ['field' => 'output.status', 'operator' => '==', 'value' => 'done'],
            $tasks[1]->skip_condition,
        );
    }

    public function test_throws_when_delegation_depth_exceeds_maximum(): void
    {
        config(['app.max_delegation_depth' => 5]);
        $execution = $this->makeExecution(['delegation_depth' => 5]);

        $gateway = $this->createMock(AiGatewayInterface::class);
        $gateway->expects($this->never())->method('complete');

        $action = new DecomposeGoalAction($gateway, $this->makeProviderResolver());

        $this->expectException(MaxDelegationDepthExceededException::class);

        $action->execute($execution);
    }

    public function test_throws_when_coordinator_belongs_to_another_team(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other Team',
            'slug' => 'other-team',
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);
        $foreignCoordinator = Agent::factory()->create(['team_id' => $otherTeam->id]);

        $execution = $this->makeExecution([
            'config_snapshot' => [
                'coordinator' => ['id' => $foreignCoordinator->id, 'name' => 'Foreign'],
                'workers' => [],
            ],
        ]);

        $action = $this->makeAction('[]');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Coordinator agent not found.');

        $action->execute($execution);
    }

    public function test_unknown_worker_assignment_leaves_task_unassigned(): void
    {
        $execution = $this->makeExecution();

        $plan = json_encode([
            [
                'title' => 'Orphan task',
                'description' => 'Assigned to a hallucinated worker',
                'assigned_to' => 'Imaginary Agent',
                'expected_output' => 'x',
            ],
        ]);

        $tasks = $this->makeAction($plan)->execute($execution);

        $this->assertCount(1, $tasks);
        $this->assertNull($tasks[0]->agent_id);
        $this->assertSame(CrewTaskStatus::Pending, $tasks[0]->status);
    }

    public function test_adversarial_process_tags_tasks_with_debate_round(): void
    {
        $execution = $this->makeExecution();
        $snapshot = $execution->config_snapshot;
        $snapshot['process_type'] = 'adversarial';
        $execution->update(['config_snapshot' => $snapshot]);

        $plan = json_encode([
            [
                'title' => 'Round 1: Hypothesis — cache invalidation',
                'description' => 'Argue that caching is the root cause',
                'assigned_to' => 'Research Analyst',
                'expected_output' => 'Evidence for the hypothesis',
            ],
        ]);

        $tasks = $this->makeAction($plan)->execute($execution);

        $this->assertCount(1, $tasks);
        $this->assertSame(1, $tasks[0]->input_context['debate_round']);
        $this->assertSame($this->worker->id, $tasks[0]->agent_id);
    }

    public function test_union_mode_collects_worker_proposals_and_orders_them(): void
    {
        $this->crew->update(['settings' => ['union_contributions' => true]]);

        $execution = $this->makeExecution();

        // Same JSON serves both the worker contribution call and the
        // coordinator ordering call (mock returns it for every complete()).
        $plan = json_encode([
            [
                'title' => 'Profile the hot path',
                'description' => 'Run the profiler against the slow endpoint',
                'expected_output' => 'A flamegraph',
            ],
            [
                'title' => 'profile the   hot path', // duplicate after normalization
                'description' => 'Duplicate proposal',
                'expected_output' => 'Ignored',
            ],
        ]);

        $tasks = $this->makeAction($plan)->execute($execution);

        // Ordered plan still contains both rows (the coordinator response is
        // the same two-task array), but tasks were created via the union path.
        $this->assertCount(2, $tasks);
        $this->assertSame('Profile the hot path', $tasks[0]->title);
        $this->assertSame(CrewTaskStatus::Pending, $tasks[0]->status);
        $this->assertSame($execution->id, $tasks[0]->crew_execution_id);

        // Worker contribution + coordinator ordering both billed (50 + 50).
        $this->assertSame(100, (int) $execution->fresh()->total_cost_credits);
    }
}
