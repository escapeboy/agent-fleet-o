<?php

namespace Tests\Feature\Domain\AgentSession;

use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\AgentSession\Models\AgentSession;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Auto-open an AgentSession when an agent-driven experiment starts real work.
 * Scope: tracks {debug, workflow, web_build}. Timing: entering Building/Executing.
 */
class OpenAgentSessionOnExecutionTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'T', 'slug' => 't-'.Str::random(6),
            'owner_id' => $user->id, 'settings' => [],
        ]);
    }

    private function experiment(?ExperimentTrack $track, ExperimentStatus $status = ExperimentStatus::Approved): Experiment
    {
        return Experiment::factory()->create([
            'team_id' => $this->team->id,
            'track' => $track,
            'status' => $status,
            'constraints' => [],
            'title' => 'x',
        ]);
    }

    private function sessionsFor(Experiment $exp)
    {
        return AgentSession::withoutGlobalScopes()->where('experiment_id', $exp->id)->get();
    }

    public function test_workflow_experiment_opens_active_session_on_executing(): void
    {
        $exp = $this->experiment(ExperimentTrack::Workflow);

        app(TransitionExperimentAction::class)->execute(
            experiment: $exp, toState: ExperimentStatus::Executing, reason: 'go',
        );

        $sessions = $this->sessionsFor($exp);
        $this->assertCount(1, $sessions);
        $session = $sessions->first();
        $this->assertSame(AgentSessionStatus::Active, $session->status);
        $this->assertNotNull($session->started_at);
        $this->assertSame($this->team->id, $session->team_id);
        $this->assertSame($exp->agent_id, $session->agent_id);
        $this->assertSame('workflow', $session->metadata['track'] ?? null);
        $this->assertSame('executing', $session->metadata['opened_on_state'] ?? null);
    }

    public function test_web_build_experiment_opens_session_on_executing(): void
    {
        $exp = $this->experiment(ExperimentTrack::WebBuild);

        app(TransitionExperimentAction::class)->execute(
            experiment: $exp, toState: ExperimentStatus::Executing, reason: 'go',
        );

        $this->assertCount(1, $this->sessionsFor($exp));
    }

    public function test_debug_experiment_opens_session_on_building(): void
    {
        $exp = $this->experiment(ExperimentTrack::Debug, ExperimentStatus::Planning);
        // Building prereq: a completed plan stage must exist.
        ExperimentStage::factory()->create([
            'experiment_id' => $exp->id,
            'team_id' => $this->team->id,
            'stage' => StageType::Planning,
            'status' => StageStatus::Completed,
            'output_snapshot' => ['plan' => 'x'],
        ]);

        app(TransitionExperimentAction::class)->execute(
            experiment: $exp, toState: ExperimentStatus::Building, reason: 'go',
        );

        $sessions = $this->sessionsFor($exp);
        $this->assertCount(1, $sessions);
        $this->assertSame('building', $sessions->first()->metadata['opened_on_state'] ?? null);
    }

    public function test_growth_experiment_opens_no_session(): void
    {
        $exp = $this->experiment(ExperimentTrack::Growth);

        app(TransitionExperimentAction::class)->execute(
            experiment: $exp, toState: ExperimentStatus::Executing, reason: 'go',
        );

        $this->assertCount(0, $this->sessionsFor($exp));
    }

    public function test_is_idempotent_when_open_session_already_exists(): void
    {
        $exp = $this->experiment(ExperimentTrack::Workflow);
        // Pre-existing open session for this experiment.
        AgentSession::create([
            'team_id' => $this->team->id,
            'experiment_id' => $exp->id,
            'status' => AgentSessionStatus::Active,
        ]);

        app(TransitionExperimentAction::class)->execute(
            experiment: $exp, toState: ExperimentStatus::Executing, reason: 'go',
        );

        $this->assertCount(1, $this->sessionsFor($exp));
    }
}
