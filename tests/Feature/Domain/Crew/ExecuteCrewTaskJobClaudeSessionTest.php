<?php

namespace Tests\Feature\Domain\Crew;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Enums\CrewProcessType;
use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Jobs\ExecuteCrewTaskJob;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewTaskExecution;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Services\ClaudeSessionContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery\MockInterface;
use Tests\TestCase;

class ExecuteCrewTaskJobClaudeSessionTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Agent $agent;

    private Crew $crew;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Session Test Team',
            'slug' => 'session-test-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => [],
        ]);

        $this->agent = Agent::factory()->create(['team_id' => $this->team->id]);
        $this->crew = Crew::factory()->create([
            'team_id' => $this->team->id,
            'coordinator_agent_id' => $this->agent->id,
            'qa_agent_id' => $this->agent->id,
        ]);
    }

    public function test_first_attempt_stores_recorded_session(): void
    {
        $task = $this->makeTask([]);
        $seen = [];

        $this->runJob($task, function (array $args) use (&$seen) {
            $context = app(ClaudeSessionContext::class);
            $seen = [
                'active' => $context->activeFor($this->agent->id),
                'stored' => $context->storedSessionId,
                'storedHash' => $context->storedHash,
            ];
            $context->record('S1', 'H1');

            return ['output' => ['result' => 'ok'], 'execution' => (object) ['cost_credits' => 0]];
        });

        $this->assertTrue($seen['active']);
        $this->assertNull($seen['stored']);
        $this->assertNull($seen['storedHash']);

        $fresh = $task->fresh();
        $this->assertSame(CrewTaskStatus::Completed, $fresh?->status);
        $this->assertSame('S1', $fresh->claude_session_id);
        $this->assertSame('H1', $fresh->claude_session_hash);
        $this->assertFalse(app(ClaudeSessionContext::class)->activeFor($this->agent->id));
    }

    public function test_retry_passes_stored_session_and_feedback_in_input(): void
    {
        $task = $this->makeTask([
            'attempt_number' => 2,
            'qa_feedback' => ['issues' => ['Too short']],
            'claude_session_id' => 'S1',
            'claude_session_hash' => 'H1',
        ]);
        $seen = [];

        $this->runJob($task, function (array $args) use (&$seen) {
            $context = app(ClaudeSessionContext::class);
            $seen = [
                'active' => $context->activeFor($this->agent->id),
                'stored' => $context->storedSessionId,
                'storedHash' => $context->storedHash,
                'input' => $args[1],
            ];
            $context->record('S2', 'H1');

            return ['output' => ['result' => 'ok'], 'execution' => (object) ['cost_credits' => 0]];
        });

        $this->assertTrue($seen['active']);
        $this->assertSame('S1', $seen['stored']);
        $this->assertSame('H1', $seen['storedHash']);
        $this->assertSame(['issues' => ['Too short']], $seen['input']['previous_feedback']);
        $this->assertArrayHasKey('retry_instructions', $seen['input']);

        $fresh = $task->fresh();
        $this->assertSame('S2', $fresh?->claude_session_id);
        $this->assertSame('H1', $fresh->claude_session_hash);
    }

    public function test_no_recorded_session_keeps_stored_values(): void
    {
        $task = $this->makeTask(['claude_session_id' => 'S1', 'claude_session_hash' => 'H1']);

        $this->runJob($task, fn () => ['output' => ['result' => 'ok'], 'execution' => (object) ['cost_credits' => 0]]);

        $fresh = $task->fresh();
        $this->assertSame('S1', $fresh?->claude_session_id);
        $this->assertSame('H1', $fresh->claude_session_hash);
    }

    public function test_failed_run_still_stores_recorded_session(): void
    {
        $task = $this->makeTask([]);

        $this->runJob($task, function () {
            app(ClaudeSessionContext::class)->record('S9', 'H9');

            throw new \RuntimeException('agent crashed');
        });

        // The orchestrator may re-queue the failed task; the session must survive for that retry.
        $fresh = $task->fresh();
        $this->assertSame('S9', $fresh?->claude_session_id);
        $this->assertSame('H9', $fresh->claude_session_hash);
    }

    /**
     * @param  array{attempt_number?: int, qa_feedback?: array<string, mixed>, claude_session_id?: string, claude_session_hash?: string}  $attributes
     */
    private function makeTask(array $attributes): CrewTaskExecution
    {
        $execution = CrewExecution::create([
            'team_id' => $this->team->id,
            'crew_id' => $this->crew->id,
            'status' => CrewExecutionStatus::Executing,
            'goal' => 'Test claude session',
            'config_snapshot' => ['process_type' => CrewProcessType::Sequential->value],
            'started_at' => now(),
        ]);

        return CrewTaskExecution::create(array_merge([
            'crew_execution_id' => $execution->id,
            'agent_id' => $this->agent->id,
            'title' => 'Task 1',
            'description' => 'Task 1 description',
            'status' => CrewTaskStatus::Assigned,
            'depends_on' => [],
            'sort_order' => 1,
        ], $attributes));
    }

    private function runJob(CrewTaskExecution $task, callable $execute): void
    {
        Bus::fake();

        $this->mock(ExecuteAgentAction::class, function (MockInterface $mock) use ($execute) {
            $mock->shouldReceive('execute')->once()->andReturnUsing(
                fn (...$args) => $execute($args),
            );
        });

        $job = new ExecuteCrewTaskJob(
            crewExecutionId: $task->crew_execution_id,
            taskExecutionId: $task->id,
            teamId: $this->team->id,
        );
        $job->handle(app(ExecuteAgentAction::class));
    }
}
