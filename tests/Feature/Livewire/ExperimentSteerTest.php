<?php

namespace Tests\Feature\Livewire;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Livewire\Experiments\ExperimentDetailPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExperimentSteerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Steer UI Test',
            'slug' => 'steer-ui-test',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    private function makeExperiment(array $overrides = []): Experiment
    {
        return Experiment::create(array_merge([
            'team_id' => $this->team->id,
            'title' => 'Steer UI Target',
            'status' => 'executing',
            'track' => 'growth',
            'description' => 't',
            'user_id' => $this->user->id,
            'initiated_by_user_id' => $this->user->id,
        ], $overrides));
    }

    public function test_open_steer_modal_resets_message_state(): void
    {
        $experiment = $this->makeExperiment();

        Livewire::test(ExperimentDetailPage::class, ['experiment' => $experiment])
            ->set('steeringMessage', 'stale')
            ->call('openSteerModal')
            ->assertSet('showSteerModal', true)
            ->assertSet('steeringMessage', '');
    }

    public function test_submit_steering_persists_message_and_closes_modal(): void
    {
        $experiment = $this->makeExperiment();

        Livewire::test(ExperimentDetailPage::class, ['experiment' => $experiment])
            ->call('openSteerModal')
            ->set('steeringMessage', 'Use staging DB, not production')
            ->call('submitSteering')
            ->assertSet('showSteerModal', false)
            ->assertSet('steeringMessage', '')
            ->assertHasNoErrors();

        $experiment->refresh();
        $this->assertSame(
            'Use staging DB, not production',
            $experiment->orchestration_config['steering_message'] ?? null,
        );
    }

    public function test_submit_steering_with_empty_message_shows_validation_error(): void
    {
        $experiment = $this->makeExperiment();

        Livewire::test(ExperimentDetailPage::class, ['experiment' => $experiment])
            ->call('openSteerModal')
            ->set('steeringMessage', '')
            ->call('submitSteering')
            ->assertHasErrors(['steeringMessage' => 'required'])
            ->assertSet('showSteerModal', true);
    }

    public function test_submit_steering_with_too_long_message_shows_validation_error(): void
    {
        $experiment = $this->makeExperiment();

        Livewire::test(ExperimentDetailPage::class, ['experiment' => $experiment])
            ->call('openSteerModal')
            ->set('steeringMessage', str_repeat('a', 2001))
            ->call('submitSteering')
            ->assertHasErrors(['steeringMessage' => 'max']);
    }

    public function test_close_steer_modal_clears_state(): void
    {
        $experiment = $this->makeExperiment();

        Livewire::test(ExperimentDetailPage::class, ['experiment' => $experiment])
            ->set('showSteerModal', true)
            ->set('steeringMessage', 'draft')
            ->call('closeSteerModal')
            ->assertSet('showSteerModal', false)
            ->assertSet('steeringMessage', '');
    }
}
