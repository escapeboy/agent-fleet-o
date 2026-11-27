<?php

namespace Tests\Feature\Console;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\Models\ExternalAgent;
use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Enums\CrewProcessType;
use App\Domain\Crew\Enums\CrewStatus;
use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Jobs\ExecuteCrewJob;
use App\Domain\Crew\Jobs\ExecuteCrewTaskJob;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewTaskExecution;
use App\Domain\Crew\Notifications\StuckCrewExecutionNotification;
use App\Domain\Shared\Enums\TeamRole;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Tests\TestCase;

class RecoverStuckCrewTasksTest extends TestCase
{
    use RefreshDatabase;

    private QueueFake $queueFake;

    private Team $team;

    private User $creator;

    private User $owner;

    private User $admin;

    private User $member;

    private Agent $agent;

    private Crew $crew;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queueFake = Queue::fake();
        Notification::fake();

        $this->owner = User::factory()->create();
        $this->creator = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->member = User::factory()->create();

        $this->team = Team::factory()->create(['owner_id' => $this->owner->id]);
        $this->team->users()->attach($this->owner, ['role' => TeamRole::Owner->value]);
        $this->team->users()->attach($this->creator, ['role' => TeamRole::Admin->value]);
        $this->team->users()->attach($this->admin, ['role' => TeamRole::Admin->value]);
        $this->team->users()->attach($this->member, ['role' => TeamRole::Member->value]);

        $this->agent = Agent::factory()->create(['team_id' => $this->team->id]);
        $this->crew = Crew::forceCreate([
            'team_id' => $this->team->id,
            'user_id' => $this->creator->id,
            'name' => 'Recovery crew',
            'slug' => 'recovery-crew-'.bin2hex(random_bytes(3)),
            'description' => 'fixture',
            'process_type' => CrewProcessType::Sequential,
            'max_task_iterations' => 3,
            'quality_threshold' => 0.7,
            'status' => CrewStatus::Active,
            'settings' => [],
            'coordinator_agent_id' => $this->agent->id,
            'qa_agent_id' => $this->agent->id,
        ]);
    }

    private function makeExecution(CrewExecutionStatus $status = CrewExecutionStatus::Executing): CrewExecution
    {
        return CrewExecution::create([
            'team_id' => $this->team->id,
            'crew_id' => $this->crew->id,
            'goal' => 'Do the work',
            'status' => $status,
            'config_snapshot' => [],
            'started_at' => now()->subMinutes(30),
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function makeTask(CrewExecution $execution, int $minutesAgo = 20, array $extra = []): CrewTaskExecution
    {
        $task = CrewTaskExecution::create([
            'crew_execution_id' => $execution->id,
            'agent_id' => $this->agent->id,
            'title' => 'Write the report',
            'description' => 'Summarise the findings',
            'status' => CrewTaskStatus::Running,
            'sort_order' => 0,
            'attempt_number' => 1,
            'max_attempts' => 3,
            'started_at' => now()->subMinutes($minutesAgo),
        ]);

        // Raw update so Eloquent does not refresh updated_at.
        DB::table('crew_task_executions')->where('id', $task->id)->update(array_merge($extra, [
            'updated_at' => now()->subMinutes($minutesAgo),
        ]));

        return $task;
    }

    private function assertNoCrewJobsPushed(): void
    {
        $this->queueFake->assertNotPushed(ExecuteCrewTaskJob::class);
        $this->queueFake->assertNotPushed(ExecuteCrewJob::class);
    }

    private function runCommand(): void
    {
        $this->artisan('tasks:recover-stuck')->assertExitCode(0);
    }

    public function test_stuck_running_crew_task_is_redispatched_once(): void
    {
        $execution = $this->makeExecution();
        $task = $this->makeTask($execution);

        $this->runCommand();

        $pushed = $this->queueFake->pushed(ExecuteCrewTaskJob::class);
        $this->assertCount(1, $pushed);
        /** @var ExecuteCrewTaskJob $job */
        $job = $pushed->first();
        $this->assertSame($execution->id, $job->crewExecutionId);
        $this->assertSame($task->id, $job->taskExecutionId);
        $this->assertSame($this->team->id, $job->teamId);
        $this->assertSame('ai-calls', $job->queue);
        $this->queueFake->assertNotPushed(ExecuteCrewJob::class);

        $task->refresh();
        $this->assertSame(1, $task->recovery_attempts);
        $this->assertSame(CrewTaskStatus::Assigned, $task->status);
        $this->assertTrue($task->updated_at->gt(now()->subMinutes(1)));

        $execution->refresh();
        $this->assertSame(CrewExecutionStatus::Executing, $execution->status);
        Notification::assertNothingSent();

        // A second run right away does nothing: the task is no longer stale.
        $this->runCommand();
        $this->assertCount(1, $this->queueFake->pushed(ExecuteCrewTaskJob::class));
    }

    public function test_crew_task_at_retry_limit_pauses_execution_and_notifies_owner_and_admins(): void
    {
        $max = (int) config('crew.recovery.max_retry_attempts');
        $execution = $this->makeExecution();
        $task = $this->makeTask($execution, 20, ['recovery_attempts' => $max]);

        $this->runCommand();

        $this->queueFake->assertNotPushed(ExecuteCrewTaskJob::class);
        $this->queueFake->assertNotPushed(ExecuteCrewJob::class);

        $execution->refresh();
        $this->assertSame(CrewExecutionStatus::Paused, $execution->status);

        $task->refresh();
        $this->assertSame(CrewTaskStatus::Pending, $task->status);
        $this->assertSame($max, $task->recovery_attempts);
        $this->assertStringContainsString('tasks:recover-stuck', (string) $task->error_message);

        Notification::assertSentToTimes($this->creator, StuckCrewExecutionNotification::class, 1);
        Notification::assertSentToTimes($this->owner, StuckCrewExecutionNotification::class, 1);
        Notification::assertSentToTimes($this->admin, StuckCrewExecutionNotification::class, 1);
        Notification::assertNotSentTo($this->member, StuckCrewExecutionNotification::class);
    }

    public function test_fresh_running_crew_task_is_left_alone(): void
    {
        $execution = $this->makeExecution();
        $task = $this->makeTask($execution, 2);

        $this->runCommand();

        $this->assertNoCrewJobsPushed();
        $task->refresh();
        $this->assertSame(CrewTaskStatus::Running, $task->status);
        $this->assertSame(0, $task->recovery_attempts);
    }

    public function test_ignores_external_agent_tasks_and_non_executing_executions(): void
    {
        $external = ExternalAgent::withoutEvents(fn () => ExternalAgent::forceCreate([
            'team_id' => $this->team->id,
            'name' => 'External',
            'slug' => 'external-'.bin2hex(random_bytes(3)),
            'endpoint_url' => 'https://example.com/agent',
            'status' => 'active',
        ]));

        $executing = $this->makeExecution();
        $externalTask = $this->makeTask($executing, 20, ['external_agent_id' => $external->id]);

        $terminatedTask = $this->makeTask($this->makeExecution(CrewExecutionStatus::Terminated));
        $pausedTask = $this->makeTask($this->makeExecution(CrewExecutionStatus::Paused));

        $this->runCommand();

        $this->assertNoCrewJobsPushed();
        foreach ([$externalTask, $terminatedTask, $pausedTask] as $task) {
            $task->refresh();
            $this->assertSame(CrewTaskStatus::Running, $task->status);
            $this->assertSame(0, $task->recovery_attempts);
        }
        Notification::assertNothingSent();
    }

    public function test_crew_recovery_can_be_disabled(): void
    {
        config(['crew.recovery.enabled' => false]);

        $task = $this->makeTask($this->makeExecution());

        $this->runCommand();

        $this->assertNoCrewJobsPushed();
        $task->refresh();
        $this->assertSame(CrewTaskStatus::Running, $task->status);
        $this->assertSame(0, $task->recovery_attempts);
    }

    public function test_stuck_crew_notification_payload(): void
    {
        $execution = $this->makeExecution();
        $task = $this->makeTask($execution);

        $notification = new StuckCrewExecutionNotification($execution, $task, 2, '20 minutes');

        $payload = $notification->toArray($this->creator);
        $this->assertSame('crew.stuck', $payload['type']);
        $this->assertSame($execution->id, $payload['crew_execution_id']);
        $this->assertSame($this->crew->id, $payload['crew_id']);
        $this->assertSame('Write the report', $payload['task_title']);
        $this->assertSame(2, $payload['recovery_attempts']);

        $this->assertContains('mail', $notification->via($this->creator));
    }
}
