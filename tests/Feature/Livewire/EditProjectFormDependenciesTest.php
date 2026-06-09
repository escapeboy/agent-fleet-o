<?php

namespace Tests\Feature\Livewire;

use App\Domain\Agent\Models\Agent;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectDependency;
use App\Domain\Shared\Models\Team;
use App\Livewire\Projects\EditProjectForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class EditProjectFormDependenciesTest extends TestCase
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

    public function test_mount_loads_existing_dependencies(): void
    {
        $project = $this->makeProject();
        $upstream = $this->makeProject();

        ProjectDependency::create([
            'project_id' => $project->id,
            'depends_on_id' => $upstream->id,
            'team_id' => $this->team->id,
            'alias' => 'research',
            'reference_type' => 'latest_run',
            'is_required' => true,
            'sort_order' => 0,
        ]);

        Livewire::test(EditProjectForm::class, ['project' => $project])
            ->assertSet('dependencies', [
                [
                    'depends_on_id' => $upstream->id,
                    'alias' => 'research',
                    'reference_type' => 'latest_run',
                    'is_required' => true,
                ],
            ]);
    }

    public function test_save_persists_new_dependency_team_scoped(): void
    {
        $project = $this->makeProject();
        $upstream = $this->makeProject();

        Livewire::test(EditProjectForm::class, ['project' => $project])
            ->set('dependencies', [
                [
                    'depends_on_id' => $upstream->id,
                    'alias' => 'research',
                    'reference_type' => 'latest_run',
                    'is_required' => true,
                ],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('project_dependencies', [
            'project_id' => $project->id,
            'depends_on_id' => $upstream->id,
            'team_id' => $this->team->id,
            'alias' => 'research',
            'reference_type' => 'latest_run',
        ]);
    }

    public function test_save_removes_dependency_when_cleared(): void
    {
        $project = $this->makeProject();
        $upstream = $this->makeProject();

        ProjectDependency::create([
            'project_id' => $project->id,
            'depends_on_id' => $upstream->id,
            'team_id' => $this->team->id,
            'alias' => 'research',
            'reference_type' => 'latest_run',
            'is_required' => true,
            'sort_order' => 0,
        ]);

        Livewire::test(EditProjectForm::class, ['project' => $project])
            ->set('dependencies', [])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('project_dependencies', [
            'project_id' => $project->id,
            'depends_on_id' => $upstream->id,
        ]);
    }

    public function test_cannot_add_another_teams_project_as_upstream(): void
    {
        $project = $this->makeProject();

        $otherTeam = Team::factory()->create();
        $foreignUpstream = Project::factory()->for($otherTeam)->create();

        Livewire::test(EditProjectForm::class, ['project' => $project])
            ->set('dependencies', [
                [
                    'depends_on_id' => $foreignUpstream->id,
                    'alias' => 'foreign',
                    'reference_type' => 'latest_run',
                    'is_required' => true,
                ],
            ])
            ->call('save')
            ->assertHasErrors('dependencies.0.depends_on_id');

        $this->assertDatabaseMissing('project_dependencies', [
            'project_id' => $project->id,
            'depends_on_id' => $foreignUpstream->id,
        ]);
    }

    public function test_save_is_forbidden_without_edit_content_gate(): void
    {
        Gate::define('edit-content', fn () => false);

        $project = $this->makeProject();
        $upstream = $this->makeProject();

        Livewire::test(EditProjectForm::class, ['project' => $project])
            ->set('dependencies', [
                [
                    'depends_on_id' => $upstream->id,
                    'alias' => 'research',
                    'reference_type' => 'latest_run',
                    'is_required' => true,
                ],
            ])
            ->call('save')
            ->assertForbidden();
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
