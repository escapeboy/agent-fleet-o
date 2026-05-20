<?php

namespace Tests\Feature\Database\Seeders;

use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use Database\Seeders\SentryAutoFixWorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SentryAutoFixWorkflowSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The seeder hard-pins the PriceX team UUID — recreate it so the
        // seeder has a valid team_id FK to attach to.
        Team::factory()->create(['id' => SentryAutoFixWorkflowSeeder::TEAM_ID]);
    }

    public function test_seeder_creates_workflow_with_three_nodes_and_two_edges(): void
    {
        $this->seed(SentryAutoFixWorkflowSeeder::class);

        $workflow = Workflow::withoutGlobalScopes()
            ->where('team_id', SentryAutoFixWorkflowSeeder::TEAM_ID)
            ->where('name', SentryAutoFixWorkflowSeeder::WORKFLOW_NAME)
            ->first();

        $this->assertNotNull($workflow, 'Seeder did not produce a Sentry Auto-Fix workflow row.');
        $this->assertSame(WorkflowStatus::Active, $workflow->status);
        $this->assertSame(1, $workflow->version);
        $this->assertSame('sentry-auto-fix', $workflow->settings['template'] ?? null);

        $nodes = $workflow->nodes()->get();
        $this->assertCount(3, $nodes, 'Workflow must contain exactly 3 nodes (start, agent, end).');

        $byType = $nodes->keyBy(fn ($n) => $n->type->value);
        $this->assertNotNull($byType->get('start'), 'Missing start node.');
        $this->assertNotNull($byType->get('agent'), 'Missing agent node.');
        $this->assertNotNull($byType->get('end'), 'Missing end node.');

        $agentNode = $byType->get('agent');
        $this->assertSame(WorkflowNodeType::Agent, $agentNode->type);
        $this->assertSame('claude-code-vps', $agentNode->config['provider']);
        $this->assertSame('claude-sonnet-4-5', $agentNode->config['model']);
        $this->assertSame(30, $agentNode->config['max_iterations']);
        $this->assertSame(300, $agentNode->config['timeout_seconds']);
        $this->assertSame(['bash', 'filesystem'], $agentNode->config['tools']);
        $this->assertNotEmpty($agentNode->config['system_prompt']);
        $this->assertStringContainsString('experiment_complete_building', $agentNode->config['system_prompt']);
        $this->assertStringContainsString('claude-code-vps', $agentNode->config['provider']);

        $edges = $workflow->edges()->get();
        $this->assertCount(2, $edges, 'Workflow must contain exactly 2 edges (start→agent, agent→end).');

        $startNode = $byType->get('start');
        $endNode = $byType->get('end');

        $startToAgent = $edges->firstWhere(fn ($e) => $e->source_node_id === $startNode->id && $e->target_node_id === $agentNode->id);
        $this->assertNotNull($startToAgent, 'Missing start→agent edge.');

        $agentToEnd = $edges->firstWhere(fn ($e) => $e->source_node_id === $agentNode->id && $e->target_node_id === $endNode->id);
        $this->assertNotNull($agentToEnd, 'Missing agent→end edge.');
    }

    public function test_re_running_seeder_is_idempotent(): void
    {
        $this->seed(SentryAutoFixWorkflowSeeder::class);
        $first = Workflow::withoutGlobalScopes()
            ->where('team_id', SentryAutoFixWorkflowSeeder::TEAM_ID)
            ->where('name', SentryAutoFixWorkflowSeeder::WORKFLOW_NAME)
            ->firstOrFail();

        $this->seed(SentryAutoFixWorkflowSeeder::class);

        $workflowCount = Workflow::withoutGlobalScopes()
            ->where('team_id', SentryAutoFixWorkflowSeeder::TEAM_ID)
            ->where('name', SentryAutoFixWorkflowSeeder::WORKFLOW_NAME)
            ->count();

        $this->assertSame(1, $workflowCount, 'Re-seeding produced a duplicate workflow row.');

        $second = Workflow::withoutGlobalScopes()
            ->where('team_id', SentryAutoFixWorkflowSeeder::TEAM_ID)
            ->where('name', SentryAutoFixWorkflowSeeder::WORKFLOW_NAME)
            ->firstOrFail();

        $this->assertSame($first->id, $second->id, 'Re-seeding replaced the workflow id.');
        $this->assertSame(3, $second->nodes()->count(), 'Re-seeding left an unexpected node count.');
        $this->assertSame(2, $second->edges()->count(), 'Re-seeding left an unexpected edge count.');
    }

    public function test_migration_wires_team_default_bug_fix_workflow_id(): void
    {
        // Simulate the companion migration: seed, then point the team's
        // default_bug_fix_workflow_id at the freshly-seeded workflow.
        $this->seed(SentryAutoFixWorkflowSeeder::class);

        $workflow = Workflow::withoutGlobalScopes()
            ->where('team_id', SentryAutoFixWorkflowSeeder::TEAM_ID)
            ->where('name', SentryAutoFixWorkflowSeeder::WORKFLOW_NAME)
            ->firstOrFail();

        Team::withoutGlobalScopes()
            ->where('id', SentryAutoFixWorkflowSeeder::TEAM_ID)
            ->update(['default_bug_fix_workflow_id' => $workflow->id]);

        $team = Team::withoutGlobalScopes()->find(SentryAutoFixWorkflowSeeder::TEAM_ID);
        $this->assertNotNull($team);
        $this->assertSame($workflow->id, $team->default_bug_fix_workflow_id);
    }
}
