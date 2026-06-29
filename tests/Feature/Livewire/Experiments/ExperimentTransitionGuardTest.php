<?php

namespace Tests\Feature\Livewire\Experiments;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Livewire\Experiments\ExperimentDetailPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExperimentTransitionGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Transition Guard Test',
            'slug' => 'transition-guard-test',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    private function makeExperiment(string $status): Experiment
    {
        return Experiment::create([
            'team_id' => $this->team->id,
            'title' => 'Guard Target',
            'status' => $status,
            'track' => 'growth',
            'description' => 't',
            'user_id' => $this->user->id,
            'initiated_by_user_id' => $this->user->id,
        ]);
    }

    public function test_pausing_a_non_pausable_experiment_flashes_error_and_does_not_throw(): void
    {
        // awaiting_approval is not in PAUSABLE_STATES — clicking pause must not 500.
        $experiment = $this->makeExperiment('awaiting_approval');

        Livewire::test(ExperimentDetailPage::class, ['experiment' => $experiment])
            ->call('pauseExperiment')
            ->assertHasNoErrors()
            ->assertSee('Invalid transition');

        $this->assertSame('awaiting_approval', $experiment->fresh()->status->value);
    }

    public function test_killing_an_already_killed_experiment_flashes_error_and_does_not_throw(): void
    {
        $experiment = $this->makeExperiment('killed');

        Livewire::test(ExperimentDetailPage::class, ['experiment' => $experiment])
            ->call('killExperiment')
            ->assertSet('showKillConfirm', false)
            ->assertSee('Invalid transition');

        $this->assertSame('killed', $experiment->fresh()->status->value);
    }
}
