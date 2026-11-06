<?php

namespace Tests\Feature\Livewire\Projects;

use App\Domain\Project\Enums\ProjectExecutionMode;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Enums\ProjectType;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectRun;
use App\Domain\Shared\Models\Team;
use App\Livewire\Projects\ProjectDetailPage;
use App\Livewire\Projects\ProjectListPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectTransitionGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Project Guard Test',
            'slug' => 'project-guard-test',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    private function makeProject(ProjectStatus $status): Project
    {
        return Project::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Guard Target',
            'status' => $status,
            'type' => ProjectType::OneShot,
            'execution_mode' => ProjectExecutionMode::Autonomous,
        ]);
    }

    public function test_detail_pause_on_draft_project_shows_error_and_does_not_throw(): void
    {
        $project = $this->makeProject(ProjectStatus::Draft);

        Livewire::test(ProjectDetailPage::class, ['project' => $project])
            ->call('pause')
            ->assertHasNoErrors()
            ->assertSee('Cannot pause project in draft state');

        $this->assertSame(ProjectStatus::Draft, $project->fresh()->status);
    }

    public function test_detail_resume_on_archived_project_shows_error_and_does_not_throw(): void
    {
        $project = $this->makeProject(ProjectStatus::Archived);

        Livewire::test(ProjectDetailPage::class, ['project' => $project])
            ->call('resume')
            ->assertSee('Cannot resume project in archived state');

        $this->assertSame(ProjectStatus::Archived, $project->fresh()->status);
    }

    public function test_detail_restart_on_draft_project_shows_error_and_does_not_throw(): void
    {
        $project = $this->makeProject(ProjectStatus::Draft);

        Livewire::test(ProjectDetailPage::class, ['project' => $project])
            ->call('restart')
            ->assertSee("Cannot restart a project in 'draft' status");

        $this->assertSame(ProjectStatus::Draft, $project->fresh()->status);
        $this->assertSame(0, ProjectRun::where('project_id', $project->id)->count());
    }

    public function test_detail_activate_on_non_draft_project_shows_error(): void
    {
        $project = $this->makeProject(ProjectStatus::Paused);

        Livewire::test(ProjectDetailPage::class, ['project' => $project])
            ->call('activate')
            ->assertSee('can only be activated from draft');

        $this->assertSame(ProjectStatus::Paused, $project->fresh()->status);
    }

    public function test_list_pause_on_completed_project_shows_error_and_does_not_throw(): void
    {
        $project = $this->makeProject(ProjectStatus::Completed);

        Livewire::test(ProjectListPage::class)
            ->call('pause', $project->id)
            ->assertSee('Cannot pause project in completed state');

        $this->assertSame(ProjectStatus::Completed, $project->fresh()->status);
    }

    public function test_list_resume_on_draft_project_shows_error_and_does_not_throw(): void
    {
        $project = $this->makeProject(ProjectStatus::Draft);

        Livewire::test(ProjectListPage::class)
            ->call('resume', $project->id)
            ->assertSee('Cannot resume project in draft state');

        $this->assertSame(ProjectStatus::Draft, $project->fresh()->status);
    }

    public function test_valid_pause_still_works(): void
    {
        $project = $this->makeProject(ProjectStatus::Active);

        Livewire::test(ProjectDetailPage::class, ['project' => $project])
            ->call('pause')
            ->assertSee('Project paused.');

        $this->assertSame(ProjectStatus::Paused, $project->fresh()->status);
    }

    public function test_detail_page_hides_actions_not_allowed_for_status(): void
    {
        $completed = $this->makeProject(ProjectStatus::Completed);

        Livewire::test(ProjectDetailPage::class, ['project' => $completed])
            ->assertDontSee('wire:click="pause"', false)
            ->assertDontSee('wire:click="resume"', false);

        $archived = $this->makeProject(ProjectStatus::Archived);

        Livewire::test(ProjectDetailPage::class, ['project' => $archived])
            ->assertDontSee('wire:click="archive"', false)
            ->assertDontSee('wire:click="restart"', false);
    }
}
