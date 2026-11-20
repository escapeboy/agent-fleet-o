<?php

namespace Tests\Feature\Domain\Workflow;

use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Actions\RestoreWorkflowVersionAction;
use App\Domain\Workflow\Actions\UpdateWorkflowAction;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowEdge;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Domain\Workflow\Models\WorkflowVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowVersionHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function actAsOwner(): array
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $this->actingAs($user);

        return [$team, $user];
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function graph(string $middleLabel, array $config = []): array
    {
        $nodes = [
            ['id' => 'n-start', 'type' => 'start', 'label' => 'Start', 'config' => [], 'position_x' => 0, 'position_y' => 0, 'order' => 0],
            ['id' => 'n-mid', 'type' => 'agent', 'label' => $middleLabel, 'config' => $config, 'position_x' => 10, 'position_y' => 100, 'order' => 1],
            ['id' => 'n-end', 'type' => 'end', 'label' => 'End', 'config' => [], 'position_x' => 0, 'position_y' => 200, 'order' => 2],
        ];
        $edges = [
            ['source_node_id' => 'n-start', 'target_node_id' => 'n-mid', 'label' => 'go'],
            ['source_node_id' => 'n-mid', 'target_node_id' => 'n-end'],
        ];

        return [$nodes, $edges];
    }

    private function save(Workflow $workflow, string $label, array $config = []): Workflow
    {
        [$nodes, $edges] = $this->graph($label, $config);

        return app(UpdateWorkflowAction::class)->execute(workflow: $workflow, nodes: $nodes, edges: $edges);
    }

    public function test_updating_active_workflow_graph_snapshots_previous_graph(): void
    {
        [$team, $user] = $this->actAsOwner();
        $workflow = Workflow::factory()->active()->create(['team_id' => $team->id, 'user_id' => $user->id]);

        $workflow = $this->save($workflow, 'First');
        $this->assertSame(2, $workflow->version);

        $versions = WorkflowVersion::query()->where('workflow_id', $workflow->id)->get();
        $this->assertCount(1, $versions);

        /** @var WorkflowVersion $snapshot */
        $snapshot = $versions->first();
        $this->assertSame(1, $snapshot->version);
        $this->assertSame($team->id, $snapshot->team_id);
        $this->assertSame($user->id, $snapshot->created_by);
        $this->assertSame([], $snapshot->graph['nodes']);

        // Second edit snapshots the graph saved in the first edit.
        $workflow = $this->save($workflow, 'Second');
        $this->assertSame(3, $workflow->version);

        /** @var WorkflowVersion $v2 */
        $v2 = WorkflowVersion::query()->where('workflow_id', $workflow->id)->where('version', 2)->firstOrFail();
        $this->assertSame(3, $v2->nodeCount());
        $this->assertSame(2, $v2->edgeCount());
        $this->assertContains('First', array_column($v2->graph['nodes'], 'label'));
    }

    public function test_draft_workflow_and_metadata_only_updates_do_not_snapshot(): void
    {
        [$team, $user] = $this->actAsOwner();

        $draft = Workflow::factory()->create(['team_id' => $team->id, 'user_id' => $user->id]);
        $this->save($draft, 'Draft edit');

        $active = Workflow::factory()->active()->create(['team_id' => $team->id, 'user_id' => $user->id]);
        app(UpdateWorkflowAction::class)->execute(workflow: $active, name: 'Renamed');

        $this->assertSame(0, WorkflowVersion::withoutGlobalScopes()->count());
    }

    public function test_restore_recreates_old_graph_as_new_version(): void
    {
        [$team, $user] = $this->actAsOwner();
        // Build the v1 graph while still a draft (no version bump), then activate.
        $workflow = Workflow::factory()->create(['team_id' => $team->id, 'user_id' => $user->id]);
        $workflow = $this->save($workflow, 'Original', ['prompt' => 'one']);
        $workflow->update(['status' => 'active']);
        $this->assertSame(1, $workflow->version);

        $workflow = $this->save($workflow, 'Changed', ['prompt' => 'two']); // v1 -> v2, snapshot v1
        $workflow = $this->save($workflow, 'Later', ['prompt' => 'three']);  // v2 -> v3, snapshot v2
        $this->assertSame(3, $workflow->version);

        /** @var WorkflowVersion $target */
        $target = WorkflowVersion::query()->where('workflow_id', $workflow->id)->where('version', 1)->firstOrFail();

        $restored = app(RestoreWorkflowVersionAction::class)->execute($workflow, $target->id);

        $this->assertSame(4, $restored->version);

        $nodes = WorkflowNode::query()->where('workflow_id', $restored->id)->orderBy('order')->get();
        $this->assertSame(['Start', 'Original', 'End'], $nodes->pluck('label')->all());
        $this->assertSame(
            ['start', 'agent', 'end'],
            $nodes->map(fn (WorkflowNode $n) => $n->getAttribute('type')?->value)->all(),
        );
        $this->assertSame(['prompt' => 'one'], $nodes[1]->getAttribute('config'));

        $ids = $nodes->pluck('id', 'label');
        $edges = WorkflowEdge::query()->where('workflow_id', $restored->id)->get();
        $this->assertCount(2, $edges);
        $pairs = $edges->map(fn (WorkflowEdge $e) => [$e->source_node_id, $e->target_node_id])->all();
        $this->assertContains([$ids['Start'], $ids['Original']], $pairs);
        $this->assertContains([$ids['Original'], $ids['End']], $pairs);

        // The graph replaced by the restore was snapshotted, so history only moves forward.
        $v3 = WorkflowVersion::query()->where('workflow_id', $workflow->id)->where('version', 3)->firstOrFail();
        $this->assertContains('Later', array_column($v3->graph['nodes'], 'label'));
        // The original snapshot is unchanged.
        $this->assertContains('Original', array_column($target->fresh()?->graph['nodes'] ?? [], 'label'));
    }

    public function test_restore_rejects_version_of_another_workflow(): void
    {
        [$team, $user] = $this->actAsOwner();
        $workflowA = Workflow::factory()->active()->create(['team_id' => $team->id, 'user_id' => $user->id]);
        $workflowB = Workflow::factory()->active()->create(['team_id' => $team->id, 'user_id' => $user->id]);

        $workflowA = $this->save($workflowA, 'A1');
        $this->save($workflowA, 'A2');
        $workflowB = $this->save($workflowB, 'B1');

        $foreign = WorkflowVersion::query()->where('workflow_id', $workflowA->id)->where('version', 2)->firstOrFail();

        try {
            app(RestoreWorkflowVersionAction::class)->execute($workflowB, $foreign->id);
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            // expected
        }

        $workflowB->refresh();
        $this->assertSame(2, $workflowB->version);
        $this->assertContains('B1', $workflowB->nodes()->pluck('label')->all());
    }

    public function test_versions_are_team_scoped(): void
    {
        [$teamA, $userA] = $this->actAsOwner();
        $workflow = Workflow::factory()->active()->create(['team_id' => $teamA->id, 'user_id' => $userA->id]);
        $this->save($workflow, 'Team A graph');

        $this->assertSame(1, WorkflowVersion::query()->count());

        $teamB = Team::factory()->create();
        $userB = User::factory()->create(['current_team_id' => $teamB->id]);
        $teamB->users()->attach($userB, ['role' => 'owner']);
        $this->actingAs($userB);

        $this->assertSame(0, WorkflowVersion::query()->count());
        $this->assertSame(1, WorkflowVersion::withoutGlobalScopes()->count());
    }
}
