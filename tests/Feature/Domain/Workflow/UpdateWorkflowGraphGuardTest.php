<?php

namespace Tests\Feature\Domain\Workflow;

use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Actions\UpdateWorkflowAction;
use App\Domain\Workflow\Models\Workflow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression for Sentry #817: an edge whose payload omits `source_node_id`
 * (or `target_node_id`) used to throw "Undefined array key" mid-transaction.
 * The guard skips malformed edges instead of fataling the whole update.
 */
class UpdateWorkflowGraphGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_edge_missing_node_id_is_skipped_not_fatal(): void
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'Guard Team',
            'slug' => 'guard-team-817',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $team->id]);
        $team->users()->attach($user, ['role' => 'owner']);

        $workflow = Workflow::factory()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'name' => 'Guard Workflow',
        ]);

        $nodes = [
            ['id' => 'start', 'type' => 'start', 'label' => 'Start'],
            ['id' => 'end', 'type' => 'end', 'label' => 'End'],
        ];

        $edges = [
            // Valid edge.
            ['source_node_id' => 'start', 'target_node_id' => 'end'],
            // Malformed edge — missing source_node_id (the #817 trigger).
            ['target_node_id' => 'end'],
            // Malformed edge — missing target_node_id.
            ['source_node_id' => 'start'],
        ];

        $result = app(UpdateWorkflowAction::class)->execute(
            workflow: $workflow,
            nodes: $nodes,
            edges: $edges,
        );

        // No exception thrown; only the one valid edge is persisted.
        $this->assertSame(2, $result->nodes()->count());
        $this->assertSame(1, $result->edges()->count());
    }
}
