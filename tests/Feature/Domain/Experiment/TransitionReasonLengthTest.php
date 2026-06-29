<?php

namespace Tests\Feature\Domain\Experiment;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStateTransition;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransitionReasonLengthTest extends TestCase
{
    use RefreshDatabase;

    public function test_transition_reason_persists_beyond_255_chars(): void
    {
        // The evaluation agent's `reasoning` can exceed 255 chars; the reason
        // column must be text, not varchar(255), or the insert throws 22001 and
        // rolls back the whole transition (Sentry #943).
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'Reason Length Test',
            'slug' => 'reason-length-test',
            'owner_id' => $user->id,
            'settings' => [],
        ]);

        $experiment = Experiment::create([
            'team_id' => $team->id,
            'title' => 'Reason Length Target',
            'status' => 'evaluating',
            'track' => 'growth',
            'description' => 't',
            'user_id' => $user->id,
            'initiated_by_user_id' => $user->id,
        ]);

        $longReason = str_repeat('The evaluation agent decided to kill this experiment. ', 50);
        $this->assertGreaterThan(255, strlen($longReason));

        $transition = ExperimentStateTransition::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'experiment_id' => $experiment->id,
            'from_state' => 'evaluating',
            'to_state' => 'killed',
            'reason' => $longReason,
            'created_at' => now(),
        ]);

        $this->assertSame($longReason, $transition->fresh()->reason);
    }
}
