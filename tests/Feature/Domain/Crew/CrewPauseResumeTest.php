<?php

namespace Tests\Feature\Domain\Crew;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Actions\DecomposeGoalAction;
use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Enums\CrewProcessType;
use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Jobs\ExecuteCrewTaskJob;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewTaskExecution;
use App\Domain\Crew\Services\CrewExecutionScope;
use App\Domain\Crew\Services\CrewOrchestrator;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery\MockInterface;
use Tests\TestCase;

class CrewPauseResumeTest extends TestCase
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
            'name' => 'Pause Resume Test Team',
            'slug' => 'pause-resume-test-'.uniqid(),
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

    public function test_dispatch_sequential_dispatches_no_task_job_when_paused(): void
    {
        Bus::fake();

        $execution = $this->makeExecution(CrewExecutionStatus::Paused, CrewProcessType::Sequential);
        $this->makeTask($execution, 1, CrewTaskStatus::Pending);
        $this->makeTask($execution, 2, CrewTaskStatus::Pending);

        app(CrewOrchestrator::class)->dispatchSequential($execution, new CrewExecutionScope($execution));

        Bus::assertNotDispatched(ExecuteCrewTaskJob::class);
    }

    public function test_task_validated_while_paused_does_not_dispatch_next_task(): void
    {
        Bus::fake();

        $execution = $this->makeExecution(CrewExecutionStatus::Paused, CrewProcessType::Sequential);
        $first = $this->makeTask($execution, 1, CrewTaskStatus::Validated);
        $second = $this->makeTask($execution, 2, CrewTaskStatus::Pending);

        app(CrewOrchestrator::class)->onTaskValidated($execution, $first);

        Bus::assertNotDispatched(ExecuteCrewTaskJob::class);
        $this->assertSame(CrewTaskStatus::Pending, $second->fresh()?->status);
        $this->assertSame(CrewExecutionStatus::Paused, $execution->fresh()?->status);
    }

    public function test_dispatch_parallel_dispatches_no_batch_when_paused(): void
    {
        Bus::fake();

        $execution = $this->makeExecution(CrewExecutionStatus::Paused, CrewProcessType::Parallel);
        for ($i = 1; $i <= 3; $i++) {
            $this->makeTask($execution, $i, CrewTaskStatus::Pending);
        }

        app(CrewOrchestrator::class)->dispatchParallel($execution, new CrewExecutionScope($execution));

        Bus::assertBatchCount(0);
    }

    public function test_task_job_does_not_call_agent_when_execution_paused(): void
    {
        $this->mock(ExecuteAgentAction::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('execute');
        });

        $execution = $this->makeExecution(CrewExecutionStatus::Paused, CrewProcessType::Sequential);
        $task = $this->makeTask($execution, 1, CrewTaskStatus::Assigned);

        $job = new ExecuteCrewTaskJob(
            crewExecutionId: $execution->id,
            taskExecutionId: $task->id,
            teamId: $this->team->id,
        );
        $job->handle(app(ExecuteAgentAction::class));

        $this->assertSame(CrewTaskStatus::Pending, $task->fresh()?->status);
    }

    public function test_run_does_not_decompose_again_when_tasks_exist(): void
    {
        Bus::fake();

        $this->mock(DecomposeGoalAction::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('execute');
        });

        $execution = $this->makeExecution(CrewExecutionStatus::Planning, CrewProcessType::Sequential);
        $first = $this->makeTask($execution, 1, CrewTaskStatus::Validated);
        $second = $this->makeTask($execution, 2, CrewTaskStatus::Pending, [$first->id]);

        app(CrewOrchestrator::class)->run($execution);

        $this->assertSame(2, CrewTaskExecution::where('crew_execution_id', $execution->id)->count());
        $this->assertSame(CrewExecutionStatus::Executing, $execution->fresh()?->status);
        Bus::assertDispatchedTimes(ExecuteCrewTaskJob::class, 1);
        Bus::assertDispatched(ExecuteCrewTaskJob::class, fn (ExecuteCrewTaskJob $job) => $job->taskExecutionId === $second->id);
    }

    public function test_resume_continues_pending_tasks_without_duplicates(): void
    {
        Bus::fake();

        $this->mock(DecomposeGoalAction::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('execute');
        });

        $execution = $this->makeExecution(CrewExecutionStatus::Executing, CrewProcessType::Sequential);
        $first = $this->makeTask($execution, 1, CrewTaskStatus::Validated);
        $second = $this->makeTask($execution, 2, CrewTaskStatus::Pending, [$first->id]);

        app(CrewOrchestrator::class)->resume($execution);

        $this->assertSame(2, CrewTaskExecution::where('crew_execution_id', $execution->id)->count());
        Bus::assertDispatchedTimes(ExecuteCrewTaskJob::class, 1);
        Bus::assertDispatched(ExecuteCrewTaskJob::class, fn (ExecuteCrewTaskJob $job) => $job->taskExecutionId === $second->id);
        $this->assertSame(CrewExecutionStatus::Executing, $execution->fresh()?->status);
    }

    public function test_resume_plans_execution_paused_during_planning(): void
    {
        Bus::fake();

        // Paused while still in Planning: the start job skipped, so no tasks exist yet.
        $execution = $this->makeExecution(CrewExecutionStatus::Executing, CrewProcessType::Sequential);

        $this->mock(DecomposeGoalAction::class, function (MockInterface $mock) {
            $mock->shouldReceive('execute')->once()->andReturnUsing(
                fn (CrewExecution $e) => [$this->makeTask($e, 1, CrewTaskStatus::Pending)],
            );
        });

        app(CrewOrchestrator::class)->resume($execution);

        $this->assertSame(CrewExecutionStatus::Executing, $execution->fresh()?->status);
        $this->assertSame(1, CrewTaskExecution::where('crew_execution_id', $execution->id)->count());
        Bus::assertDispatchedTimes(ExecuteCrewTaskJob::class, 1);
    }

    public function test_resume_parallel_does_not_requeue_tasks_still_in_queue(): void
    {
        Bus::fake();

        $execution = $this->makeExecution(CrewExecutionStatus::Executing, CrewProcessType::Parallel);
        $this->makeTask($execution, 1, CrewTaskStatus::Pending);
        $this->makeTask($execution, 2, CrewTaskStatus::Pending);

        app(CrewOrchestrator::class)->dispatchParallel($execution, new CrewExecutionScope($execution));
        Bus::assertBatchCount(1);

        // Paused and resumed before the queued jobs were picked up.
        $execution->update(['status' => CrewExecutionStatus::Paused]);
        $execution->update(['status' => CrewExecutionStatus::Executing]);

        app(CrewOrchestrator::class)->resume($execution->fresh() ?? $execution);

        Bus::assertBatchCount(1);
    }

    private function makeExecution(CrewExecutionStatus $status, CrewProcessType $processType): CrewExecution
    {
        return CrewExecution::create([
            'team_id' => $this->team->id,
            'crew_id' => $this->crew->id,
            'status' => $status,
            'goal' => 'Test pause and resume',
            'config_snapshot' => [
                'process_type' => $processType->value,
            ],
            'started_at' => now(),
        ]);
    }

    /**
     * @param  list<string>  $dependsOn
     */
    private function makeTask(CrewExecution $execution, int $order, CrewTaskStatus $status, array $dependsOn = []): CrewTaskExecution
    {
        return CrewTaskExecution::create([
            'crew_execution_id' => $execution->id,
            'agent_id' => $this->agent->id,
            'title' => "Task {$order}",
            'description' => "Task {$order} description",
            'status' => $status,
            'depends_on' => $dependsOn,
            'sort_order' => $order,
        ]);
    }
}
