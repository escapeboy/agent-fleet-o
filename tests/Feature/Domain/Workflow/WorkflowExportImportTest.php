<?php

namespace Tests\Feature\Domain\Workflow;

use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Actions\ExportWorkflowAction;
use App\Domain\Workflow\Actions\ImportWorkflowAction;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowEdge;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class WorkflowExportImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private Workflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team-wei',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);

        $this->workflow = Workflow::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'name' => 'Test Workflow',
            'status' => WorkflowStatus::Draft,
        ]);
    }

    public function test_export_produces_v2_envelope_with_checksum(): void
    {
        $startNode = WorkflowNode::factory()->start()->create([
            'workflow_id' => $this->workflow->id,
            'order' => 0,
        ]);
        $endNode = WorkflowNode::factory()->end()->create([
            'workflow_id' => $this->workflow->id,
            'order' => 1,
        ]);

        WorkflowEdge::factory()->create([
            'workflow_id' => $this->workflow->id,
            'source_node_id' => $startNode->id,
            'target_node_id' => $endNode->id,
        ]);

        $action = app(ExportWorkflowAction::class);
        $result = $action->execute($this->workflow);

        $this->assertIsArray($result);
        $this->assertEquals('2.0', $result['format_version']);
        $this->assertEquals('fleetq', $result['generator']);
        $this->assertArrayHasKey('checksum', $result);
        $this->assertArrayHasKey('exported_at', $result);
        $this->assertArrayHasKey('workflow', $result);
        $this->assertArrayHasKey('nodes', $result);
        $this->assertArrayHasKey('edges', $result);
        $this->assertArrayHasKey('references', $result);

        // Verify checksum is valid
        $expectedChecksum = hash('sha256', json_encode([
            $result['workflow'],
            $result['nodes'],
            $result['edges'],
        ]));
        $this->assertEquals($expectedChecksum, $result['checksum']);
    }

    public function test_export_includes_edge_case_value_and_channels(): void
    {
        $startNode = WorkflowNode::factory()->start()->create([
            'workflow_id' => $this->workflow->id,
        ]);
        $endNode = WorkflowNode::factory()->end()->create([
            'workflow_id' => $this->workflow->id,
        ]);

        WorkflowEdge::factory()->create([
            'workflow_id' => $this->workflow->id,
            'source_node_id' => $startNode->id,
            'target_node_id' => $endNode->id,
            'case_value' => 'success',
            'source_channel' => 'output',
            'target_channel' => 'input',
        ]);

        $action = app(ExportWorkflowAction::class);
        $result = $action->execute($this->workflow);

        $edge = $result['edges'][0];
        $this->assertEquals('success', $edge['case_value']);
        $this->assertEquals('output', $edge['source_channel']);
        $this->assertEquals('input', $edge['target_channel']);
    }

    public function test_export_yaml_format(): void
    {
        WorkflowNode::factory()->start()->create([
            'workflow_id' => $this->workflow->id,
        ]);

        $action = app(ExportWorkflowAction::class);
        $result = $action->execute($this->workflow, 'yaml');

        $this->assertIsString($result);
        $this->assertStringContainsString('format_version:', $result);
        $this->assertStringContainsString('Test Workflow', $result);
    }

    public function test_import_v2_with_reference_resolution(): void
    {
        $agent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Research Agent',
        ]);

        $exportData = [
            'format_version' => '2.0',
            'generator' => 'fleetq',
            'generator_version' => '1.1.0',
            'exported_at' => now()->toIso8601String(),
            'workflow' => [
                'name' => 'Imported Workflow',
                'description' => 'A test workflow',
                'max_loop_iterations' => 5,
                'estimated_cost_credits' => null,
                'settings' => [],
                'node_count' => 2,
                'agent_node_count' => 1,
            ],
            'nodes' => [
                [
                    'id' => 'old-node-1',
                    'type' => 'start',
                    'label' => 'Start',
                    'position_x' => 0,
                    'position_y' => 0,
                    'config' => [],
                    'order' => 0,
                    'agent_id' => null,
                    'skill_id' => null,
                    'crew_id' => null,
                    'agent_hint' => null,
                    'skill_hint' => null,
                    'crew_hint' => null,
                ],
                [
                    'id' => 'old-node-2',
                    'type' => 'agent',
                    'label' => 'Research',
                    'position_x' => 100,
                    'position_y' => 100,
                    'config' => [],
                    'order' => 1,
                    'agent_id' => 'some-old-agent-id',
                    'skill_id' => null,
                    'crew_id' => null,
                    'agent_hint' => ['name' => 'Research Agent', 'role' => 'researcher'],
                    'skill_hint' => null,
                    'crew_hint' => null,
                ],
            ],
            'edges' => [
                [
                    'id' => 'old-edge-1',
                    'source_node_id' => 'old-node-1',
                    'target_node_id' => 'old-node-2',
                    'condition' => null,
                    'label' => null,
                    'is_default' => true,
                    'sort_order' => 0,
                    'case_value' => null,
                    'source_channel' => null,
                    'target_channel' => null,
                ],
            ],
            'references' => [
                'agents' => [['name' => 'Research Agent', 'type' => 'agent']],
                'skills' => [],
                'crews' => [],
            ],
        ];

        // Add checksum
        $exportData['checksum'] = hash('sha256', json_encode([
            $exportData['workflow'],
            $exportData['nodes'],
            $exportData['edges'],
        ]));

        $action = app(ImportWorkflowAction::class);
        $result = $action->execute($exportData, $this->team->id, $this->user->id);

        $this->assertInstanceOf(Workflow::class, $result['workflow']);
        $this->assertEquals('Imported Workflow', $result['workflow']->name);
        $this->assertEquals(WorkflowStatus::Draft, $result['workflow']->status);
        $this->assertTrue($result['checksum_valid']);
        $this->assertEmpty($result['unresolved_references']);

        // Verify agent was resolved
        $agentNode = $result['workflow']->nodes()->where('type', 'agent')->first();
        $this->assertEquals($agent->id, $agentNode->agent_id);
    }

    public function test_import_v1_backward_compat(): void
    {
        $v1Data = [
            'version' => '1.0',
            'exported_at' => now()->toIso8601String(),
            'workflow' => [
                'name' => 'V1 Workflow',
                'description' => 'Legacy format',
                'max_loop_iterations' => 10,
                'estimated_cost_credits' => 500,
                'settings' => [],
                'node_count' => 1,
                'agent_node_count' => 0,
            ],
            'nodes' => [
                [
                    'id' => 'node-1',
                    'type' => 'start',
                    'label' => 'Start',
                    'position_x' => 0,
                    'position_y' => 0,
                    'config' => [],
                    'order' => 0,
                ],
            ],
            'edges' => [],
        ];

        $action = app(ImportWorkflowAction::class);
        $result = $action->execute($v1Data, $this->team->id, $this->user->id);

        $this->assertInstanceOf(Workflow::class, $result['workflow']);
        $this->assertEquals('V1 Workflow', $result['workflow']->name);
        $this->assertEquals(WorkflowStatus::Draft, $result['workflow']->status);
        $this->assertNull($result['checksum_valid']);
    }

    public function test_import_yaml_format(): void
    {
        $yamlContent = <<<'YAML'
format_version: '2.0'
generator: fleetq
generator_version: '1.1.0'
exported_at: '2026-03-20T00:00:00+00:00'
checksum: placeholder
workflow:
  name: YAML Workflow
  description: Imported from YAML
  max_loop_iterations: 5
  estimated_cost_credits: null
  settings: []
  node_count: 1
  agent_node_count: 0
nodes:
  - id: node-1
    type: start
    label: Start
    position_x: 0
    position_y: 0
    config: []
    order: 0
    agent_id: null
    skill_id: null
    crew_id: null
    agent_hint: null
    skill_hint: null
    crew_hint: null
edges: []
references:
  agents: []
  skills: []
  crews: []
YAML;

        // Parse to fix checksum
        $parsed = Yaml::parse($yamlContent);
        $parsed['checksum'] = hash('sha256', json_encode([
            $parsed['workflow'],
            $parsed['nodes'],
            $parsed['edges'],
        ]));
        $yamlContent = Yaml::dump($parsed, 10, 2);

        $action = app(ImportWorkflowAction::class);
        $result = $action->execute($yamlContent, $this->team->id, $this->user->id);

        $this->assertInstanceOf(Workflow::class, $result['workflow']);
        $this->assertEquals('YAML Workflow', $result['workflow']->name);
        $this->assertTrue($result['checksum_valid']);
    }

    public function test_import_rejects_over_500_nodes(): void
    {
        $nodes = [];
        for ($i = 0; $i <= 500; $i++) {
            $nodes[] = [
                'id' => "node-{$i}",
                'type' => 'agent',
                'label' => "Node {$i}",
                'position_x' => 0,
                'position_y' => 0,
                'config' => [],
                'order' => $i,
            ];
        }

        $data = [
            'format_version' => '2.0',
            'generator' => 'fleetq',
            'checksum' => 'irrelevant',
            'workflow' => [
                'name' => 'Too Big',
                'description' => null,
                'settings' => [],
            ],
            'nodes' => $nodes,
            'edges' => [],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('500 nodes');

        $action = app(ImportWorkflowAction::class);
        $action->execute($data, $this->team->id, $this->user->id);
    }

    public function test_import_resolves_agents_by_name_not_id(): void
    {
        $agent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Writer Agent',
        ]);

        // Use a completely different original_id — resolution must be by name
        $data = [
            'format_version' => '2.0',
            'generator' => 'fleetq',
            'workflow' => [
                'name' => 'Name Resolution Test',
                'description' => null,
                'settings' => [],
            ],
            'nodes' => [
                [
                    'id' => 'n1',
                    'type' => 'agent',
                    'label' => 'Write',
                    'position_x' => 0,
                    'position_y' => 0,
                    'config' => [],
                    'order' => 0,
                    'agent_id' => '00000000-0000-0000-0000-000000000000',
                    'skill_id' => null,
                    'crew_id' => null,
                    'agent_hint' => ['name' => 'Writer Agent', 'role' => 'writer'],
                    'skill_hint' => null,
                    'crew_hint' => null,
                ],
            ],
            'edges' => [],
            'references' => [
                'agents' => [['name' => 'Writer Agent', 'type' => 'agent']],
                'skills' => [],
                'crews' => [],
            ],
        ];

        $data['checksum'] = hash('sha256', json_encode([
            $data['workflow'],
            $data['nodes'],
            $data['edges'],
        ]));

        $action = app(ImportWorkflowAction::class);
        $result = $action->execute($data, $this->team->id, $this->user->id);

        $importedNode = $result['workflow']->nodes()->first();
        $this->assertEquals($agent->id, $importedNode->agent_id);
        $this->assertEmpty($result['unresolved_references']);
    }
}
