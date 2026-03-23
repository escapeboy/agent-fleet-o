<?php

namespace Tests\Feature\Domain\Workflow;

use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Domain\Workflow\Services\PolicyExporter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyExporterTest extends TestCase
{
    use RefreshDatabase;

    private PolicyExporter $exporter;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);

        $this->exporter = app(PolicyExporter::class);
    }

    public function test_export_returns_json_string(): void
    {
        $workflow = Workflow::factory()->create(['team_id' => $this->team->id]);

        $result = $this->exporter->export($workflow);

        $this->assertIsString($result);
        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
    }

    public function test_export_includes_required_top_level_keys(): void
    {
        $workflow = Workflow::factory()->create(['team_id' => $this->team->id]);

        $policy = json_decode($this->exporter->export($workflow), true);

        $this->assertEquals('fleetq.io/v1', $policy['apiVersion']);
        $this->assertEquals('WorkflowPolicy', $policy['kind']);
        $this->assertArrayHasKey('metadata', $policy);
        $this->assertArrayHasKey('approval_gates', $policy);
        $this->assertArrayHasKey('budget_limits', $policy);
        $this->assertArrayHasKey('tool_restrictions', $policy);
        $this->assertArrayHasKey('data_classification', $policy);
    }

    public function test_export_metadata_contains_workflow_info(): void
    {
        $workflow = Workflow::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'My Governance Workflow',
        ]);

        $policy = json_decode($this->exporter->export($workflow), true);

        $this->assertEquals($workflow->id, $policy['metadata']['workflow_id']);
        $this->assertEquals('My Governance Workflow', $policy['metadata']['name']);
        $this->assertArrayHasKey('exported_at', $policy['metadata']);
    }

    public function test_export_extracts_human_task_nodes_as_approval_gates(): void
    {
        $workflow = Workflow::factory()->create(['team_id' => $this->team->id]);

        WorkflowNode::factory()->create([
            'workflow_id' => $workflow->id,
            'type' => 'human_task',
            'label' => 'Manager Approval',
            'config' => ['timeout_hours' => 48],
            'order' => 1,
        ]);

        WorkflowNode::factory()->create([
            'workflow_id' => $workflow->id,
            'type' => 'agent',
            'label' => 'Summarise',
            'config' => [],
            'order' => 2,
        ]);

        $policy = json_decode($this->exporter->export($workflow), true);

        $this->assertCount(1, $policy['approval_gates']);
        $this->assertEquals('Manager Approval', $policy['approval_gates'][0]['label']);
        $this->assertEquals(48, $policy['approval_gates'][0]['timeout_hours']);
    }

    public function test_export_uses_default_timeout_when_not_set(): void
    {
        $workflow = Workflow::factory()->create(['team_id' => $this->team->id]);

        WorkflowNode::factory()->create([
            'workflow_id' => $workflow->id,
            'type' => 'human_task',
            'label' => 'Review',
            'config' => [],
            'order' => 1,
        ]);

        $policy = json_decode($this->exporter->export($workflow), true);

        $this->assertEquals(24, $policy['approval_gates'][0]['timeout_hours']);
    }

    public function test_export_collects_allowed_tool_ids_from_agent_nodes(): void
    {
        $workflow = Workflow::factory()->create(['team_id' => $this->team->id]);

        WorkflowNode::factory()->create([
            'workflow_id' => $workflow->id,
            'type' => 'agent',
            'label' => 'Step 1',
            'config' => ['allowed_tool_ids' => ['tool-a', 'tool-b']],
            'order' => 1,
        ]);

        WorkflowNode::factory()->create([
            'workflow_id' => $workflow->id,
            'type' => 'agent',
            'label' => 'Step 2',
            'config' => ['allowed_tool_ids' => ['tool-b', 'tool-c']],
            'order' => 2,
        ]);

        $policy = json_decode($this->exporter->export($workflow), true);

        $toolIds = $policy['tool_restrictions']['allowed_tool_ids'];
        sort($toolIds);

        $this->assertEquals(['tool-a', 'tool-b', 'tool-c'], $toolIds);
    }

    public function test_export_budget_limits_includes_alert_threshold(): void
    {
        $workflow = Workflow::factory()->create(['team_id' => $this->team->id]);

        $policy = json_decode($this->exporter->export($workflow), true);

        $this->assertEquals(80, $policy['budget_limits']['alert_threshold_pct']);
    }
}
