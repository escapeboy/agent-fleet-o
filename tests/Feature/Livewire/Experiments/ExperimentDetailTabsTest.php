<?php

namespace Tests\Feature\Livewire\Experiments;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Shared\Models\Team;
use App\Livewire\Experiments\ExperimentDetailPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Debug experiments deliver a pull request, not artifacts/tasks/workflow steps.
 * The detail page must show a tailored tab set (no guaranteed-empty workflow /
 * tasks / reasoning tabs) and surface the PR as the result.
 */
class ExperimentDetailTabsTest extends TestCase
{
    use RefreshDatabase;

    private function experiment(ExperimentTrack $track): Experiment
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Gate::before(fn () => true);

        $team = Team::create([
            'name' => 'T', 'slug' => 't-'.Str::random(6),
            'owner_id' => $user->id, 'settings' => [],
        ]);

        // A workflow_graph makes hasWorkflow() true — a debug experiment carries
        // this vestigially, and the debug tab set must still win over it.
        return Experiment::factory()->create([
            'team_id' => $team->id,
            'track' => $track,
            'status' => ExperimentStatus::Completed,
            'constraints' => ['workflow_graph' => ['nodes' => [], 'edges' => []]],
        ]);
    }

    private function buildingStageWithPr(Experiment $exp, array $prUrls): void
    {
        ExperimentStage::factory()->create([
            'team_id' => $exp->team_id,
            'experiment_id' => $exp->id,
            'stage' => StageType::Building,
            'status' => StageStatus::Completed,
            'output_snapshot' => ['pr_urls' => $prUrls, 'builder' => 'warm_build'],
        ]);
    }

    public function test_debug_experiment_uses_tailored_tabs_and_surfaces_pr(): void
    {
        $exp = $this->experiment(ExperimentTrack::Debug);
        $this->buildingStageWithPr($exp, ['https://github.com/escapeboy/agent-fleet/pull/68']);

        $c = Livewire::test(ExperimentDetailPage::class, ['experiment' => $exp]);

        // Defaults to Activity (debug has no Timeline / Tasks default).
        $this->assertSame('activity', $c->get('activeTab'));

        // PR surfaced as the result.
        $c->assertSee('https://github.com/escapeboy/agent-fleet/pull/68');

        // Relevant tabs present; guaranteed-empty workflow/reasoning tabs hidden,
        // even though hasWorkflow() is true.
        $c->assertSee('Execution Log')
            ->assertDontSee('Execution Chain')
            ->assertDontSee('Time Travel')
            ->assertDontSee('Worklog')
            ->assertDontSee('Reasoning');
    }

    public function test_non_debug_experiment_keeps_full_tabs_and_no_pr_banner(): void
    {
        $exp = $this->experiment(ExperimentTrack::Growth);
        $this->buildingStageWithPr($exp, ['https://github.com/escapeboy/agent-fleet/pull/68']);

        $c = Livewire::test(ExperimentDetailPage::class, ['experiment' => $exp]);

        // Non-debug experiment keeps its full standard tab set (Timeline, Reasoning
        // are absent from the tailored debug set)...
        $c->assertSee('Timeline')
            ->assertSee('Reasoning');

        // ...and the debug-only PR result banner is not shown for non-debug runs.
        $c->assertDontSee('Result — Pull Request');
    }
}
