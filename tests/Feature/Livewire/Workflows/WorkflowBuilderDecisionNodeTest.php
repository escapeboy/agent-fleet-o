<?php

namespace Tests\Feature\Livewire\Workflows;

use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Models\Workflow;
use App\Livewire\Workflows\WorkflowBuilderPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The builder palette is hand-written Blade, not generated from WorkflowNodeType,
 * so a new node type is unreachable in the UI until a button is added for it.
 */
class WorkflowBuilderDecisionNodeTest extends TestCase
{
    use RefreshDatabase;

    private function actAsOwner(): Team
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $this->actingAs($user);
        session(['current_team_id' => $team->id]);

        return $team;
    }

    public function test_the_palette_offers_a_decision_node(): void
    {
        $this->actAsOwner();

        Livewire::test(WorkflowBuilderPage::class)
            ->assertSee("addNode('decision')", false)
            ->assertSee("selectedNode.type === 'decision'", false);
    }

    public function test_a_decision_node_round_trips_through_save_with_its_config(): void
    {
        $this->actAsOwner();

        $nodes = [
            ['id' => 'node-start', 'type' => 'start', 'label' => 'Start', 'config' => [], 'position_x' => 0, 'position_y' => 0, 'order' => 0],
            ['id' => 'node-dec', 'type' => 'decision', 'label' => 'Decision', 'config' => [
                'state' => '{{input}}',
                'questions' => ['is_urgent' => ['type' => 'choice', 'instructions' => 'Is this urgent?', 'criteria' => ['yes', 'no']]],
                'driver' => 'jev',
                'min_confidence' => '0.7',
            ], 'position_x' => 0, 'position_y' => 100, 'order' => 1],
            ['id' => 'node-end', 'type' => 'end', 'label' => 'End', 'config' => [], 'position_x' => 0, 'position_y' => 200, 'order' => 2],
        ];

        $edges = [
            ['id' => 'e1', 'source_node_id' => 'node-start', 'target_node_id' => 'node-dec'],
            ['id' => 'e2', 'source_node_id' => 'node-dec', 'target_node_id' => 'node-end'],
        ];

        Livewire::test(WorkflowBuilderPage::class)
            ->set('name', 'Triage')
            ->call('saveGraph', $nodes, $edges)
            ->call('save');

        $this->assertNull(session('error'), 'save() flashed: '.session('error'));

        $workflow = Workflow::withoutGlobalScopes()->where('name', 'Triage')->firstOrFail();
        $node = $workflow->nodes()->withoutGlobalScopes()->where('type', WorkflowNodeType::Decision)->firstOrFail();

        $this->assertSame('jev', $node->config['driver']);
        $this->assertSame('{{input}}', $node->config['state']);
        $this->assertSame(['yes', 'no'], $node->config['questions']['is_urgent']['criteria']);

        // The builder sends id-based edges; CreateWorkflowAction used to read
        // only `source_node_index` and abort, so a new graph could not be saved.
        $this->assertSame(2, $workflow->edges()->withoutGlobalScopes()->count());
    }
}
