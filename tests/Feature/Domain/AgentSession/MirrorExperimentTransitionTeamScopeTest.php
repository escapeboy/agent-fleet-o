<?php

namespace Tests\Feature\Domain\AgentSession;

use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\AgentSession\Listeners\MirrorExperimentTransition;
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
 * Regression: MirrorExperimentTransition must route a transition into the
 * session that belongs to the experiment's OWN team, never a same-experiment_id
 * session planted by another team. Transitions fire in Horizon (console) context
 * where TeamScope short-circuits, so a team-implicit query would leak.
 */
class MirrorExperimentTransitionTeamScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_routes_transition_to_owning_team_session_only(): void
    {
        $user = User::factory()->create();
        $teamA = Team::create(['name' => 'A', 'slug' => 'a-'.Str::random(6), 'owner_id' => $user->id, 'settings' => []]);
        $teamB = Team::create(['name' => 'B', 'slug' => 'b-'.Str::random(6), 'owner_id' => $user->id, 'settings' => []]);

        $exp = Experiment::factory()->create([
            'team_id' => $teamA->id,
            'track' => ExperimentTrack::Workflow,
            'status' => ExperimentStatus::Executing,
            'constraints' => [],
            'title' => 'x',
        ]);

        $sessionA = AgentSession::create([
            'team_id' => $teamA->id,
            'experiment_id' => $exp->id,
            'status' => AgentSessionStatus::Active,
        ]);

        // Decoy: another team plants an open session with the SAME experiment_id,
        // created LATER so a latest('created_at') query without a team filter
        // would prefer it.
        $decoy = AgentSession::create([
            'team_id' => $teamB->id,
            'experiment_id' => $exp->id,
            'status' => AgentSessionStatus::Active,
        ]);

        app(MirrorExperimentTransition::class)->handle(
            new ExperimentTransitioned($exp, ExperimentStatus::Executing, ExperimentStatus::CollectingMetrics),
        );

        $this->assertSame(1, $sessionA->events()->count(), 'owning-team session gets the event');
        $this->assertSame(0, $decoy->events()->count(), 'decoy cross-team session gets nothing');
    }
}
