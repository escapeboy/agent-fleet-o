<?php

namespace Tests\Feature\Domain\AgentSession;

use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\AgentSession\Models\AgentSession;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * End-to-end proof of listener ordering: Open (before Mirror) → Mirror → Close
 * (after Mirror). A debug experiment entering Executing short-circuits to
 * Completed; the session must capture BOTH the opening transition and the
 * terminal transition, then end Completed. If Open ran after DispatchNextStageJob
 * or Close ran before Mirror, one of the events would be missing.
 */
class SessionLifecycleOrderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_debug_run_captures_open_and_terminal_transitions_and_closes(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'T', 'slug' => 't-'.Str::random(6),
            'owner_id' => $user->id, 'settings' => [],
        ]);
        $exp = Experiment::factory()->create([
            'team_id' => $team->id,
            'track' => ExperimentTrack::Debug,
            'status' => ExperimentStatus::Approved,
            'constraints' => [],
            'title' => 'x',
        ]);

        app(TransitionExperimentAction::class)->execute(
            experiment: $exp, toState: ExperimentStatus::Executing, reason: 'go',
        );

        $session = AgentSession::withoutGlobalScopes()->where('experiment_id', $exp->id)->firstOrFail();
        $this->assertSame(AgentSessionStatus::Completed, $session->status);
        $this->assertNotNull($session->ended_at);

        $toStates = $session->events()->get()
            ->map(fn ($e) => $e->payload['to_state'] ?? null)
            ->filter()
            ->all();

        $this->assertContains('executing', $toStates, 'opening transition should be mirrored');
        $this->assertContains('completed', $toStates, 'terminal transition should be mirrored');
    }
}
