<?php

namespace Tests\Feature\Livewire\Projects;

use App\Domain\Agent\Models\Agent;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Models\Team;
use App\Livewire\Projects\EditProjectForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EditProjectFormQualityGatesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->team = Team::factory()->create(['owner_id' => $this->user->id]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    public function test_mount_hydrates_done_gate_from_settings(): void
    {
        $project = $this->makeProject(['settings' => ['done_gate_enabled' => true, 'done_gate_kill_switch' => false]]);

        Livewire::test(EditProjectForm::class, ['project' => $project])
            ->assertSet('doneGateEnabled', true)
            ->assertSet('doneGateKillSwitch', false);
    }

    public function test_save_persists_done_gate_to_project_settings(): void
    {
        $project = $this->makeProject(['settings' => []]);

        Livewire::test(EditProjectForm::class, ['project' => $project])
            ->set('doneGateEnabled', true)
            ->set('doneGateKillSwitch', true)
            ->call('save')
            ->assertHasNoErrors();

        $project->refresh();
        $this->assertTrue($project->settings['done_gate_enabled']);
        $this->assertTrue($project->settings['done_gate_kill_switch']);
    }

    public function test_save_preserves_other_settings_keys(): void
    {
        $project = $this->makeProject([
            'settings' => [
                'unrelated_setting' => 'keep me',
                'done_gate_enabled' => false,
            ],
        ]);

        Livewire::test(EditProjectForm::class, ['project' => $project])
            ->set('doneGateEnabled', true)
            ->call('save')
            ->assertHasNoErrors();

        $project->refresh();
        $this->assertSame('keep me', $project->settings['unrelated_setting']);
        $this->assertTrue($project->settings['done_gate_enabled']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeProject(array $overrides = []): Project
    {
        $agent = Agent::factory()->for($this->team)->create();

        return Project::factory()->for($this->team)->create(array_merge([
            'agent_config' => ['lead_agent_id' => $agent->id],
        ], $overrides));
    }
}
