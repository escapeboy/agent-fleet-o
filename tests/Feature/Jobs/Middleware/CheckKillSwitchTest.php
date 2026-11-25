<?php

namespace Tests\Feature\Jobs\Middleware;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Jobs\Middleware\CheckKillSwitch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    public function test_releases_crew_job_when_linked_experiment_is_paused(): void
    {
        $execution = $this->makeExecution('executing', $this->makeExperiment('paused'));
        $job = $this->makeCrewJob($execution->id);

        $this->assertFalse($this->runMiddleware($job));
        $this->assertSame(60, $job->releasedWith);
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

    public function test_skips_without_release_when_crew_execution_is_paused(): void
    {
        $job = $this->makeCrewJob($this->makeExecution('paused')->id);

        $this->assertFalse($this->runMiddleware($job));
        $this->assertNull($job->releasedWith);
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
