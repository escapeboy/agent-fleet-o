<?php

namespace Tests\Feature\Livewire;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Shared\Models\Team;
use App\Livewire\Experiments\ExperimentCheckpointsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class ExperimentCheckpointsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Checkpoint UI Test',
            'slug' => 'checkpoint-ui-test',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    private function makeExperiment(Team $team, array $overrides = []): Experiment
    {
        return Experiment::create(array_merge([
            'team_id' => $team->id,
            'title' => 'Checkpoint Target',
            'status' => 'executing',
            'track' => 'growth',
            'description' => 't',
            'user_id' => $this->user->id,
            'initiated_by_user_id' => $this->user->id,
        ], $overrides));
    }

    public function test_lists_checkpoints_for_a_team_experiment(): void
    {
        $experiment = $this->makeExperiment($this->team);

        $withCheckpoint = PlaybookStep::create([
            'experiment_id' => $experiment->id,
            'order' => 1,
            'status' => 'running',
            'worker_id' => 'worker-abc',
            'idempotency_key' => 'idem-123',
            'checkpoint_version' => 2,
            'checkpoint_data' => ['cursor' => 42],
        ]);

        // A step without checkpoint data must NOT appear.
        PlaybookStep::create([
            'experiment_id' => $experiment->id,
            'order' => 2,
            'status' => 'pending',
        ]);

        $component = Livewire::test(ExperimentCheckpointsPage::class, ['experiment' => $experiment->id])
            ->assertOk()
            ->assertSee('worker-abc')
            ->assertSee('idem-123');

        $this->assertCount(1, $component->instance()->checkpoints());
        $this->assertTrue($component->instance()->checkpoints()->contains('id', $withCheckpoint->id));
    }

    public function test_cannot_view_another_teams_experiment(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other Team',
            'slug' => 'other-team',
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);
        $foreign = $this->makeExperiment($otherTeam, ['user_id' => $otherUser->id, 'initiated_by_user_id' => $otherUser->id]);

        Livewire::test(ExperimentCheckpointsPage::class, ['experiment' => $foreign->id])
            ->assertStatus(404);
    }

    public function test_unauthorized_user_cannot_resume_from_checkpoint(): void
    {
        // Community edition's edit-content gate is always-true; deny it explicitly
        // to exercise the per-action authorization guard.
        Gate::define('edit-content', fn () => false);

        $experiment = $this->makeExperiment($this->team);
        PlaybookStep::create([
            'experiment_id' => $experiment->id,
            'order' => 1,
            'status' => 'running',
            'checkpoint_data' => ['cursor' => 1],
        ]);

        Livewire::test(ExperimentCheckpointsPage::class, ['experiment' => $experiment->id])
            ->call('resumeFromCheckpoint')
            ->assertForbidden();
    }
}
