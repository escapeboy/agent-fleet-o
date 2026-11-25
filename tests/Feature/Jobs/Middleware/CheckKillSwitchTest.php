<?php

namespace Tests\Feature\Jobs\Middleware;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Actions\ValidateTaskOutputAction;
use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Enums\CrewProcessType;
use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Jobs\ExecuteCrewTaskJob;
use App\Domain\Crew\Jobs\ValidateCrewTaskJob;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewTaskExecution;
use App\Domain\Crew\Services\CrewExecutionScope;
use App\Domain\Crew\Services\CrewOrchestrator;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Jobs\Middleware\CheckBudgetAvailable;
use App\Jobs\Middleware\CheckKillSwitch;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class CheckKillSwitchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private Crew $crew;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Kill Switch Team',
            'slug' => 'kill-switch-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);

        $coordinator = Agent::factory()->create(['team_id' => $this->team->id]);
        $qa = Agent::factory()->create(['team_id' => $this->team->id]);

        $this->crew = Crew::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'coordinator_agent_id' => $coordinator->id,
            'qa_agent_id' => $qa->id,
        ]);
    }

    private function makeExperiment(string $status): Experiment
    {
        return Experiment::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Crew Experiment',
            'status' => $status,
            'track' => 'growth',
            'budget_cap_credits' => 0,
            'budget_spent_credits' => 0,
        ]);
    }

    private function makeExecution(string $status, ?Experiment $experiment = null): CrewExecution
    {
        return CrewExecution::create([
            'team_id' => $this->team->id,
            'crew_id' => $this->crew->id,
            'experiment_id' => $experiment?->id,
            'goal' => 'Do the thing',
            'status' => $status,
            'config_snapshot' => [],
        ]);
    }

    private function makeTask(CrewExecution $execution, CrewTaskStatus $status): CrewTaskExecution
    {
        return CrewTaskExecution::create([
            'crew_execution_id' => $execution->id,
            'agent_id' => $this->crew->coordinator_agent_id,
            'title' => 'Task 1',
            'description' => 'Task 1 description',
            'status' => $status,
            'depends_on' => [],
            'sort_order' => 1,
        ]);
    }

    /**
     * Runs a real crew job through the kill-switch and budget middlewares it declares,
     * with a queue job that must never be released.
     */
    private function runThroughMiddleware(ExecuteCrewTaskJob|ValidateCrewTaskJob $job, Closure $handle): void
    {
        /** @var QueueJob&MockInterface $queueJob */
        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldNotReceive('release');
        $queueJob->shouldIgnoreMissing();
        $job->setJob($queueJob);

        (new Pipeline(app()))
            ->send($job)
            ->through([new CheckKillSwitch, new CheckBudgetAvailable])
            ->then($handle);
    }

    private function makeCrewJob(string $crewExecutionId): object
    {
        return new class($crewExecutionId)
        {
            public ?int $releasedWith = null;

            public function __construct(public readonly string $crewExecutionId) {}

            public function release(int $delay = 0): void
            {
                $this->releasedWith = $delay;
            }
        };
    }

    private function makeExperimentJob(string $experimentId): object
    {
        return new class($experimentId)
        {
            public ?int $releasedWith = null;

            public function __construct(public readonly string $experimentId) {}

            public function release(int $delay = 0): void
            {
                $this->releasedWith = $delay;
            }
        };
    }

    private function runMiddleware(object $job): bool
    {
        $called = false;
        (new CheckKillSwitch)->handle($job, function () use (&$called) {
            $called = true;
        });

        return $called;
    }

    public function test_skips_crew_job_when_linked_experiment_is_killed(): void
    {
        $execution = $this->makeExecution('executing', $this->makeExperiment('killed'));
        $job = $this->makeCrewJob($execution->id);

        $this->assertFalse($this->runMiddleware($job));
        $this->assertNull($job->releasedWith);
    }

    public function test_pauses_crew_execution_without_release_when_linked_experiment_is_paused(): void
    {
        $execution = $this->makeExecution('executing', $this->makeExperiment('paused'));
        $job = $this->makeCrewJob($execution->id);

        $this->assertTrue($this->runMiddleware($job));
        $this->assertNull($job->releasedWith);
        $this->assertSame(CrewExecutionStatus::Paused, $execution->fresh()?->status);
    }

    public function test_paused_experiment_pauses_real_crew_task_job_and_crew_resume_requeues_it(): void
    {
        $this->mock(ExecuteAgentAction::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('execute');
        });

        $execution = $this->makeExecution('executing', $this->makeExperiment('paused'));
        $execution->update(['config_snapshot' => ['process_type' => CrewProcessType::Sequential->value]]);
        $task = $this->makeTask($execution, CrewTaskStatus::Assigned);

        $job = new ExecuteCrewTaskJob(
            crewExecutionId: $execution->id,
            taskExecutionId: $task->id,
            teamId: $this->team->id,
        );
        $this->runThroughMiddleware($job, fn (ExecuteCrewTaskJob $j) => $j->handle(app(ExecuteAgentAction::class)));

        // Not released (which would use up the job's attempts, see runThroughMiddleware);
        // the crew is paused and the job's own paused handling put the task back to Pending.
        $this->assertSame(CrewExecutionStatus::Paused, $execution->fresh()?->status);
        $this->assertSame(CrewTaskStatus::Pending, $task->fresh()?->status);

        Bus::fake();
        $fresh = CrewExecution::withoutGlobalScopes()->findOrFail($execution->id);
        $fresh->update(['status' => CrewExecutionStatus::Executing]);
        app(CrewOrchestrator::class)->dispatchSequential($fresh, new CrewExecutionScope($fresh));

        Bus::assertDispatched(ExecuteCrewTaskJob::class, fn (ExecuteCrewTaskJob $j) => $j->taskExecutionId === $task->id);
    }

    public function test_queued_validation_still_records_result_while_crew_is_paused(): void
    {
        $execution = $this->makeExecution('paused');
        $task = $this->makeTask($execution, CrewTaskStatus::Completed);

        $this->mock(ValidateTaskOutputAction::class, function (MockInterface $mock) {
            $mock->shouldReceive('execute')->once()->andReturn(['passed' => true]);
        });
        $this->mock(CrewOrchestrator::class, function (MockInterface $mock) {
            $mock->shouldReceive('onTaskValidated')->once();
        });

        $job = new ValidateCrewTaskJob(
            crewExecutionId: $execution->id,
            taskExecutionId: $task->id,
            teamId: $this->team->id,
        );
        $this->runThroughMiddleware($job, fn (ValidateCrewTaskJob $j) => $j->handle(
            app(ValidateTaskOutputAction::class),
            app(CrewOrchestrator::class),
        ));
    }

    public function test_skips_crew_job_when_execution_is_terminated(): void
    {
        $job = $this->makeCrewJob($this->makeExecution('terminated')->id);

        $this->assertFalse($this->runMiddleware($job));
        $this->assertNull($job->releasedWith);
    }

    public function test_skips_crew_job_when_execution_has_failed(): void
    {
        $job = $this->makeCrewJob($this->makeExecution('failed')->id);

        $this->assertFalse($this->runMiddleware($job));
        $this->assertNull($job->releasedWith);
    }

    public function test_passes_job_through_when_only_crew_execution_is_paused(): void
    {
        $job = $this->makeCrewJob($this->makeExecution('paused')->id);

        $this->assertTrue($this->runMiddleware($job));
        $this->assertNull($job->releasedWith);
    }

    public function test_paused_crew_task_job_through_middleware_is_requeued_on_resume(): void
    {
        $this->mock(ExecuteAgentAction::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('execute');
        });

        $execution = $this->makeExecution('paused');
        $execution->update(['config_snapshot' => ['process_type' => CrewProcessType::Sequential->value]]);
        $task = $this->makeTask($execution, CrewTaskStatus::Assigned);

        $job = new ExecuteCrewTaskJob(
            crewExecutionId: $execution->id,
            taskExecutionId: $task->id,
            teamId: $this->team->id,
        );
        $this->runThroughMiddleware($job, fn (ExecuteCrewTaskJob $j) => $j->handle(app(ExecuteAgentAction::class)));

        $this->assertSame(CrewTaskStatus::Pending, $task->fresh()?->status);

        // Resume: the task is Pending again, so the orchestrator dispatches it.
        Bus::fake();
        $fresh = CrewExecution::withoutGlobalScopes()->findOrFail($execution->id);
        $fresh->update(['status' => CrewExecutionStatus::Executing]);
        app(CrewOrchestrator::class)->dispatchSequential($fresh, new CrewExecutionScope($fresh));

        Bus::assertDispatched(ExecuteCrewTaskJob::class, fn (ExecuteCrewTaskJob $j) => $j->taskExecutionId === $task->id);
    }

    public function test_runs_executing_crew_job_without_experiment(): void
    {
        $job = $this->makeCrewJob($this->makeExecution('executing')->id);

        $this->assertTrue($this->runMiddleware($job));
    }

    public function test_runs_crew_job_with_running_experiment(): void
    {
        $execution = $this->makeExecution('executing', $this->makeExperiment('executing'));

        $this->assertTrue($this->runMiddleware($this->makeCrewJob($execution->id)));
    }

    public function test_runs_crew_job_with_unknown_execution(): void
    {
        $this->assertTrue($this->runMiddleware($this->makeCrewJob((string) Str::uuid7())));
    }

    public function test_experiment_job_behaviour_is_unchanged(): void
    {
        $this->assertTrue($this->runMiddleware($this->makeExperimentJob($this->makeExperiment('executing')->id)));
        $this->assertFalse($this->runMiddleware($this->makeExperimentJob($this->makeExperiment('killed')->id)));

        $paused = $this->makeExperimentJob($this->makeExperiment('paused')->id);
        $this->assertFalse($this->runMiddleware($paused));
        $this->assertSame(60, $paused->releasedWith);
    }

    public function test_runs_job_without_experiment_or_crew_execution(): void
    {
        $job = new class
        {
            public string $someOtherProp = 'value';
        };

        $this->assertTrue($this->runMiddleware($job));
    }
}
