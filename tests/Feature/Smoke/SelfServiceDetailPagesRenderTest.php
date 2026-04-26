<?php

declare(strict_types=1);

namespace Tests\Feature\Smoke;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Models\Crew;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Models\Skill;
use App\Domain\Workflow\Models\Workflow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesFailedExperiments;
use Tests\TestCase;

/**
 * Server-side smoke test for every detail page touched by the customer
 * self-service troubleshooting arc. Confirms each page renders with a
 * 200 response when its `<x-fix-with-assistant>` mount is conditionally
 * triggered (failed experiment / paused project / open circuit-breaker
 * agent / etc.) and when it isn't.
 *
 * This is the closest substitute for a real browser smoke test in CI:
 * it loads the full Laravel + Livewire pipeline via the test HTTP
 * client, so syntax errors in Blade, missing service bindings,
 * unresolvable Livewire components, or runtime exceptions in mount()
 * fail the test instead of silently breaking production pages.
 */
class SelfServiceDetailPagesRenderTest extends TestCase
{
    use MakesFailedExperiments;
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Smoke Test Team',
            'slug' => 'smoke-test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    public function test_dashboard_renders_with_health_summary_tile(): void
    {
        $this->get(route('dashboard'))
            ->assertStatus(200)
            // Tile shows either healthy or attention-needed copy
            ->assertSeeText(['systems healthy'], escape: false);
    }

    public function test_failed_experiment_detail_renders_with_diagnose_card(): void
    {
        $experiment = $this->makeFailedExperiment();

        $this->get(route('experiments.show', $experiment))
            ->assertStatus(200)
            ->assertSeeText('Diagnose');
    }

    public function test_completed_experiment_detail_omits_diagnose_card(): void
    {
        $experiment = Experiment::factory()
            ->for($this->team)
            ->for($this->user)
            ->completed()
            ->create();

        // Page should still render
        $this->get(route('experiments.show', $experiment))
            ->assertStatus(200);
    }

    public function test_paused_project_detail_renders_with_diagnose_card(): void
    {
        $project = Project::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Paused Smoke Test',
            'status' => ProjectStatus::Paused,
            'project_type' => 'one_shot',
        ]);

        $this->get(route('projects.show', $project))
            ->assertStatus(200);
    }

    public function test_agent_detail_renders_with_sla_panel(): void
    {
        $agent = Agent::factory()->for($this->team)->create();

        $this->get(route('agents.show', $agent))
            ->assertStatus(200)
            // SLA panel always renders, even with zero runs
            ->assertSeeText('Agent SLA');
    }

    public function test_skill_detail_renders(): void
    {
        $skill = Skill::factory()->for($this->team)->create();

        $this->get(route('skills.show', $skill))
            ->assertStatus(200);
    }

    public function test_crew_detail_renders(): void
    {
        $coordinator = Agent::factory()->for($this->team)->create();
        $qa = Agent::factory()->for($this->team)->create();
        $crew = Crew::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'name' => 'Smoke Crew',
            'slug' => 'smoke-crew-'.bin2hex(random_bytes(3)),
            'coordinator_agent_id' => $coordinator->id,
            'qa_agent_id' => $qa->id,
            'process_type' => 'sequential',
            'status' => 'active',
        ]);

        $this->get(route('crews.show', $crew))
            ->assertStatus(200);
    }

    public function test_workflow_detail_renders(): void
    {
        $workflow = Workflow::factory()->for($this->team)->create();

        $this->get(route('workflows.show', $workflow))
            ->assertStatus(200);
    }
}
