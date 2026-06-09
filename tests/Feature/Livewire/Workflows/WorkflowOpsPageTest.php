<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Workflows;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowEdge;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Livewire\Workflows\WorkflowOpsPage;
use App\Livewire\Workflows\WorkflowSimulationPanel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class WorkflowOpsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Ops Test Team',
            'slug' => 'ops-test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    /**
     * Builds a failed workflow experiment with one completed step whose node
     * has a compensation node defined (so it surfaces in the compensation view).
     */
    private function makeCompensatedFailedExperiment(Team $team): Experiment
    {
        $workflow = Workflow::factory()->for($team)->create();
        $compensationNode = WorkflowNode::factory()->for($workflow)->create();
        $node = WorkflowNode::factory()->for($workflow)->create([
            'compensation_node_id' => $compensationNode->id,
        ]);

        $experiment = Experiment::factory()->for($team)->create([
            'workflow_id' => $workflow->id,
            'status' => 'execution_failed',
        ]);

        PlaybookStep::create([
            'experiment_id' => $experiment->id,
            'workflow_node_id' => $node->id,
            'order' => 0,
            'status' => 'completed',
        ]);

        return $experiment;
    }

    public function test_lists_failed_runs_that_triggered_compensation(): void
    {
        $experiment = $this->makeCompensatedFailedExperiment($this->team);

        Livewire::test(WorkflowOpsPage::class)
            ->assertSee($experiment->title)
            ->tap(function ($component): void {
                $runs = $component->viewData('runs');
                $this->assertCount(1, $runs);
                $this->assertSame(1, $runs->first()['compensated_count']);
            });
    }

    public function test_excludes_failed_runs_without_compensation_nodes(): void
    {
        $workflow = Workflow::factory()->for($this->team)->create();
        $node = WorkflowNode::factory()->for($workflow)->create(); // no compensation_node_id

        $experiment = Experiment::factory()->for($this->team)->create([
            'workflow_id' => $workflow->id,
            'status' => 'execution_failed',
        ]);

        PlaybookStep::create([
            'experiment_id' => $experiment->id,
            'workflow_node_id' => $node->id,
            'order' => 0,
            'status' => 'completed',
        ]);

        Livewire::test(WorkflowOpsPage::class)
            ->tap(fn ($component) => $this->assertCount(0, $component->viewData('runs')));
    }

    public function test_does_not_leak_other_team_compensation_runs(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other Ops',
            'slug' => 'other-ops',
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);

        $this->makeCompensatedFailedExperiment($otherTeam);

        Livewire::test(WorkflowOpsPage::class)
            ->tap(fn ($component) => $this->assertCount(0, $component->viewData('runs')));
    }

    public function test_simulation_renders_predicted_path_for_team_workflow(): void
    {
        $workflow = Workflow::factory()->for($this->team)->create();

        $start = WorkflowNode::factory()->for($workflow)->start()->create();
        $agent = WorkflowNode::factory()->for($workflow)->create([
            'type' => WorkflowNodeType::Agent,
            'label' => 'Do the thing',
        ]);
        $end = WorkflowNode::factory()->for($workflow)->end()->create();

        WorkflowEdge::factory()->for($workflow)->create([
            'source_node_id' => $start->id,
            'target_node_id' => $agent->id,
        ]);
        WorkflowEdge::factory()->for($workflow)->create([
            'source_node_id' => $agent->id,
            'target_node_id' => $end->id,
        ]);

        Livewire::test(WorkflowSimulationPanel::class, ['workflow' => $workflow])
            ->call('simulate')
            ->assertSet('terminationStatus', 'completed')
            ->assertSee('Do the thing')
            ->tap(function ($component) use ($start, $agent, $end): void {
                $path = collect($component->get('executedPath'))->pluck('id')->all();
                $this->assertSame([$start->id, $agent->id, $end->id], $path);
            });
    }

    public function test_simulate_is_forbidden_without_edit_content(): void
    {
        // Base community edition grants edit-content to everyone; deny it here to
        // prove the per-action guard exists (cloud overrides the gate by role).
        Gate::define('edit-content', fn () => false);

        $workflow = Workflow::factory()->for($this->team)->create();
        WorkflowNode::factory()->for($workflow)->start()->create();

        Livewire::test(WorkflowSimulationPanel::class, ['workflow' => $workflow])
            ->call('simulate')
            ->assertForbidden();
    }
}
