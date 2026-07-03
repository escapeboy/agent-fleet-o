<?php

namespace Tests\Feature\Domain\AgentSession;

use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\AgentSession\Listeners\CloseAgentSessionOnTerminal;
use App\Domain\AgentSession\Models\AgentSession;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Terminal/failed experiment transitions close the associated open AgentSession
 * with the mapped status. Unit-level: exercises the listener's state mapping
 * directly, independent of the experiment transition map.
 */
class CloseAgentSessionOnTerminalTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'T', 'slug' => 't-'.Str::random(6),
            'owner_id' => $user->id, 'settings' => [],
        ]);
    }

    private function experimentWithOpenSession(): array
    {
        $exp = Experiment::factory()->create([
            'team_id' => $this->team->id,
            'track' => ExperimentTrack::Workflow,
            'status' => ExperimentStatus::Executing,
            'constraints' => [],
            'title' => 'x',
        ]);
        $session = AgentSession::create([
            'team_id' => $this->team->id,
            'experiment_id' => $exp->id,
            'status' => AgentSessionStatus::Active,
            'started_at' => now(),
        ]);

        return [$exp, $session];
    }

    private function fire(Experiment $exp, ExperimentStatus $to): void
    {
        app(CloseAgentSessionOnTerminal::class)->handle(
            new ExperimentTransitioned($exp, ExperimentStatus::Executing, $to),
        );
    }

    public function test_completed_maps_to_completed(): void
    {
        [$exp, $session] = $this->experimentWithOpenSession();
        $this->fire($exp, ExperimentStatus::Completed);
        $session->refresh();
        $this->assertSame(AgentSessionStatus::Completed, $session->status);
        $this->assertNotNull($session->ended_at);
    }

    public function test_killed_maps_to_cancelled(): void
    {
        [$exp, $session] = $this->experimentWithOpenSession();
        $this->fire($exp, ExperimentStatus::Killed);
        $session->refresh();
        $this->assertSame(AgentSessionStatus::Cancelled, $session->status);
    }

    public function test_execution_failed_maps_to_failed(): void
    {
        [$exp, $session] = $this->experimentWithOpenSession();
        $this->fire($exp, ExperimentStatus::ExecutionFailed);
        $session->refresh();
        $this->assertSame(AgentSessionStatus::Failed, $session->status);
    }

    public function test_non_terminal_transition_leaves_session_open(): void
    {
        [$exp, $session] = $this->experimentWithOpenSession();
        $this->fire($exp, ExperimentStatus::CollectingMetrics);
        $session->refresh();
        $this->assertSame(AgentSessionStatus::Active, $session->status);
        $this->assertNull($session->ended_at);
    }

    public function test_no_open_session_is_a_noop(): void
    {
        $exp = Experiment::factory()->create([
            'team_id' => $this->team->id,
            'track' => ExperimentTrack::Workflow,
            'status' => ExperimentStatus::Executing,
            'constraints' => [],
            'title' => 'x',
        ]);
        $this->fire($exp, ExperimentStatus::Completed);
        $this->assertCount(0, AgentSession::withoutGlobalScopes()->where('experiment_id', $exp->id)->get());
    }
}
