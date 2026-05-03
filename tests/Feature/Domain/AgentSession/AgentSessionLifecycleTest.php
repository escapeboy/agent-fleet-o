<?php

namespace Tests\Feature\Domain\AgentSession;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentSession\Actions\AppendSessionEventAction;
use App\Domain\AgentSession\Actions\CancelAgentSessionAction;
use App\Domain\AgentSession\Actions\CreateAgentSessionAction;
use App\Domain\AgentSession\Actions\SleepAgentSessionAction;
use App\Domain\AgentSession\Actions\WakeAgentSessionAction;
use App\Domain\AgentSession\Enums\AgentSessionEventKind;
use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\AgentSession\Listeners\MirrorExperimentTransition;
use App\Domain\AgentSession\Models\AgentSession;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentSessionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_then_wake_marks_session_active_and_records_wake_event(): void
    {
        $team = Team::factory()->create();
        $agent = Agent::factory()->for($team)->create();

        $session = app(CreateAgentSessionAction::class)->execute(
            teamId: $team->id,
            agentId: $agent->id,
        );
        $this->assertSame(AgentSessionStatus::Pending, $session->status);

        $context = app(WakeAgentSessionAction::class)->execute($session, sandboxId: 'sb-1');

        $session->refresh();
        $this->assertSame(AgentSessionStatus::Active, $session->status);
        $this->assertSame('sb-1', $session->last_known_sandbox_id);
        $this->assertGreaterThan(0, $context->lastSeq);
        $this->assertNotNull(collect($context->recentEvents)->firstWhere('kind', 'wake'));
    }

    public function test_append_event_idempotent_on_duplicate_seq(): void
    {
        $team = Team::factory()->create();
        $session = app(CreateAgentSessionAction::class)->execute(teamId: $team->id);

        $append = app(AppendSessionEventAction::class);
        $first = $append->execute($session, AgentSessionEventKind::Note, ['msg' => 'hi'], seq: 1);
        $dup = $append->execute($session, AgentSessionEventKind::Note, ['msg' => 'should-not-overwrite'], seq: 1);

        $this->assertSame($first->id, $dup->id);
        $this->assertSame(1, $session->refresh()->events()->count());
    }

    public function test_sleep_marks_sleeping_and_logs_event(): void
    {
        $team = Team::factory()->create();
        $session = app(CreateAgentSessionAction::class)->execute(teamId: $team->id);
        app(WakeAgentSessionAction::class)->execute($session);

        app(SleepAgentSessionAction::class)->execute($session->refresh(), reason: 'detached');
        $session->refresh();

        $this->assertSame(AgentSessionStatus::Sleeping, $session->status);
        $sleepEvent = $session->events()->where('kind', 'sleep')->first();
        $this->assertNotNull($sleepEvent);
        $this->assertSame('detached', $sleepEvent->payload['reason'] ?? null);
    }

    public function test_cancel_is_terminal_and_subsequent_sleep_is_noop(): void
    {
        $team = Team::factory()->create();
        $session = app(CreateAgentSessionAction::class)->execute(teamId: $team->id);

        app(CancelAgentSessionAction::class)->execute($session);
        $session->refresh();
        $this->assertSame(AgentSessionStatus::Cancelled, $session->status);

        app(SleepAgentSessionAction::class)->execute($session, reason: 'should-noop');
        $this->assertSame(AgentSessionStatus::Cancelled, $session->refresh()->status);
    }

    public function test_mirror_experiment_transition_appends_transition_event(): void
    {
        $team = Team::factory()->create();
        $experiment = Experiment::factory()->for($team)->create([
            'status' => ExperimentStatus::Executing,
        ]);
        $session = app(CreateAgentSessionAction::class)->execute(
            teamId: $team->id,
            experimentId: $experiment->id,
        );
        app(WakeAgentSessionAction::class)->execute($session);

        $event = new ExperimentTransitioned(
            experiment: $experiment,
            fromState: ExperimentStatus::Executing,
            toState: ExperimentStatus::CollectingMetrics,
        );
        app(MirrorExperimentTransition::class)->handle($event);

        $session->refresh();
        $transitionEvent = $session->events()->where('kind', 'transition')->first();
        $this->assertNotNull($transitionEvent);
        $this->assertSame('executing', $transitionEvent->payload['from_state'] ?? null);
        $this->assertSame('collecting_metrics', $transitionEvent->payload['to_state'] ?? null);
    }

    public function test_session_scoped_to_team(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $sessionA = app(CreateAgentSessionAction::class)->execute(teamId: $teamA->id);
        $sessionB = app(CreateAgentSessionAction::class)->execute(teamId: $teamB->id);

        $countA = AgentSession::withoutGlobalScopes()->where('team_id', $teamA->id)->count();
        $this->assertSame(1, $countA);
        $this->assertNotSame($sessionA->id, $sessionB->id);
    }
}
