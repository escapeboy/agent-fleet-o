<?php

namespace Tests\Feature\Livewire\Workflows;

use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Actions\UpdateWorkflowAction;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowVersion;
use App\Livewire\Workflows\WorkflowBuilderPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkflowBuilderVersionsPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_versions_panel_lists_snapshots_and_restore_reloads_graph(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $this->actingAs($user);
        session(['current_team_id' => $team->id]);

        // v1 graph built while draft, then activated.
        $workflow = Workflow::factory()->create(['team_id' => $team->id, 'user_id' => $user->id]);
        $workflow = app(UpdateWorkflowAction::class)->execute(
            workflow: $workflow,
            nodes: [
                ['id' => 's', 'type' => 'start', 'label' => 'Start'],
                ['id' => 'm', 'type' => 'agent', 'label' => 'Version One Node'],
                ['id' => 'e', 'type' => 'end', 'label' => 'End'],
            ],
            edges: [
                ['source_node_id' => 's', 'target_node_id' => 'm'],
                ['source_node_id' => 'm', 'target_node_id' => 'e'],
            ],
        );
        $workflow->update(['status' => 'active']);

        // v1 -> v2 snapshots the v1 graph.
        $workflow = app(UpdateWorkflowAction::class)->execute(
            workflow: $workflow,
            nodes: [
                ['id' => 's', 'type' => 'start', 'label' => 'Start'],
                ['id' => 'e', 'type' => 'end', 'label' => 'End'],
            ],
            edges: [['source_node_id' => 's', 'target_node_id' => 'e']],
        );

        $version = WorkflowVersion::query()->where('workflow_id', $workflow->id)->firstOrFail();

        Livewire::test(WorkflowBuilderPage::class, ['workflow' => $workflow])
            ->assertDontSee('Restore this version')
            ->set('showVersionsPanel', true)
            ->assertSee('v1')
            ->assertSee('3 nodes')
            ->assertSee('2 edges')
            ->assertSee($user->name)
            ->assertSee('Restore this version')
            ->call('restoreVersion', $version->id)
            ->assertRedirect(route('workflows.edit', $workflow));

        $workflow->refresh();
        $this->assertSame(3, $workflow->version);
        $this->assertContains('Version One Node', $workflow->nodes()->pluck('label')->all());
    }
}
