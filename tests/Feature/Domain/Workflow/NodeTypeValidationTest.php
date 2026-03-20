<?php

namespace Tests\Feature\Domain\Workflow;

use App\Domain\Agent\Models\Agent;
use App\Domain\Workflow\Actions\ValidateWorkflowGraphAction;
use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowEdge;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Domain\Workflow\Services\GraphValidator;
use App\Domain\Workflow\Services\NodeTypeCompatibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NodeTypeValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_port_schema_defined_for_all_node_types(): void
    {
        foreach (WorkflowNodeType::cases() as $type) {
            $schema = $type->portSchema();

            $this->assertArrayHasKey('inputs', $schema, "portSchema for {$type->value} missing 'inputs'");
            $this->assertArrayHasKey('outputs', $schema, "portSchema for {$type->value} missing 'outputs'");
            $this->assertIsArray($schema['inputs']);
            $this->assertIsArray($schema['outputs']);

            foreach ($schema['inputs'] as $port) {
                $this->assertArrayHasKey('name', $port);
                $this->assertArrayHasKey('type', $port);
            }
            foreach ($schema['outputs'] as $port) {
                $this->assertArrayHasKey('name', $port);
                $this->assertArrayHasKey('type', $port);
            }
        }
    }

    public function test_compatible_types_pass_validation(): void
    {
        // Agent (output: text) -> HumanTask (input: text|structured) — compatible via 'text'
        $workflow = $this->buildLinearWorkflow(
            WorkflowNodeType::Agent,
            WorkflowNodeType::HumanTask,
        );

        $validator = app(GraphValidator::class);
        $validator->validate($workflow);
        $warnings = $validator->getWarnings();

        $typeWarnings = array_filter($warnings, fn ($w) => $w['type'] === 'data_type_incompatible');
        $this->assertEmpty($typeWarnings, 'Compatible types should produce no incompatibility warnings');
    }

    public function test_incompatible_types_produce_warnings(): void
    {
        // DynamicFork (output: text) -> DynamicFork (input: array) — incompatible
        $workflow = Workflow::factory()->create();
        $agent = Agent::factory()->create(['team_id' => $workflow->team_id]);

        $start = WorkflowNode::factory()->start()->create(['workflow_id' => $workflow->id]);
        $fork1 = WorkflowNode::factory()->create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::DynamicFork,
            'label' => 'Fork1',
            'config' => ['fork_source' => 'items'],
        ]);
        $fork2 = WorkflowNode::factory()->create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::DynamicFork,
            'label' => 'Fork2',
            'config' => ['fork_source' => 'items'],
        ]);
        $end = WorkflowNode::factory()->end()->create(['workflow_id' => $workflow->id]);

        WorkflowEdge::factory()->create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $start->id,
            'target_node_id' => $fork1->id,
        ]);
        WorkflowEdge::factory()->create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $fork1->id,
            'target_node_id' => $fork2->id,
        ]);
        WorkflowEdge::factory()->create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $fork2->id,
            'target_node_id' => $end->id,
        ]);

        $validator = app(GraphValidator::class);
        $validator->validate($workflow);
        $warnings = $validator->getWarnings();

        $typeWarnings = array_filter($warnings, fn ($w) => $w['type'] === 'data_type_incompatible');
        $this->assertNotEmpty($typeWarnings, 'Incompatible types should produce a warning');
    }

    public function test_any_type_accepts_everything(): void
    {
        $this->assertTrue(NodeTypeCompatibility::isCompatible('text', 'any'));
        $this->assertTrue(NodeTypeCompatibility::isCompatible('any', 'text'));
        $this->assertTrue(NodeTypeCompatibility::isCompatible('any', 'any'));
        $this->assertTrue(NodeTypeCompatibility::isCompatible('structured', 'any'));
        $this->assertTrue(NodeTypeCompatibility::isCompatible('artifact[]', 'any'));
    }

    public function test_union_types_supported(): void
    {
        $this->assertTrue(NodeTypeCompatibility::isCompatible('text', 'text|structured'));
        $this->assertTrue(NodeTypeCompatibility::isCompatible('structured', 'text|structured'));
        $this->assertFalse(NodeTypeCompatibility::isCompatible('array', 'text|structured'));
        $this->assertTrue(NodeTypeCompatibility::isCompatible('text|array', 'text|structured'));
    }

    public function test_passthrough_resolution_walks_predecessors(): void
    {
        // Agent (text) -> Conditional (passthrough) -> HumanTask (text|structured)
        // Passthrough should resolve to 'text' from Agent
        $nodeMap = [
            'agent-1' => ['type' => WorkflowNodeType::Agent],
            'cond-1' => ['type' => WorkflowNodeType::Conditional],
        ];

        $edgesByTarget = [
            'cond-1' => [(object) ['source_node_id' => 'agent-1']],
        ];

        $resolved = NodeTypeCompatibility::resolvePassthroughType('cond-1', $nodeMap, $edgesByTarget);
        $this->assertEquals('text', $resolved);
    }

    public function test_passthrough_chain_resolution(): void
    {
        // Agent (text) -> Conditional (passthrough) -> Switch (passthrough)
        $nodeMap = [
            'agent-1' => ['type' => WorkflowNodeType::Agent],
            'cond-1' => ['type' => WorkflowNodeType::Conditional],
            'switch-1' => ['type' => WorkflowNodeType::Switch],
        ];

        $edgesByTarget = [
            'cond-1' => [(object) ['source_node_id' => 'agent-1']],
            'switch-1' => [(object) ['source_node_id' => 'cond-1']],
        ];

        $resolved = NodeTypeCompatibility::resolvePassthroughType('switch-1', $nodeMap, $edgesByTarget);
        $this->assertEquals('text', $resolved);
    }

    public function test_do_while_back_edges_excluded(): void
    {
        // Start -> Agent -> DoWhile -> Agent (back-edge from DoWhile body back to DoWhile) -> End
        $workflow = Workflow::factory()->create();
        $agent = Agent::factory()->create(['team_id' => $workflow->team_id]);

        $start = WorkflowNode::factory()->start()->create(['workflow_id' => $workflow->id]);
        $agentNode = WorkflowNode::factory()->create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::Agent,
            'label' => 'AgentNode',
            'agent_id' => $agent->id,
        ]);
        $doWhile = WorkflowNode::factory()->create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::DoWhile,
            'label' => 'DoWhileNode',
            'config' => ['break_condition' => 'done === true'],
        ]);
        $loopBody = WorkflowNode::factory()->create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::Agent,
            'label' => 'LoopBody',
            'agent_id' => $agent->id,
        ]);
        $end = WorkflowNode::factory()->end()->create(['workflow_id' => $workflow->id]);

        // Start -> Agent
        WorkflowEdge::factory()->create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $start->id,
            'target_node_id' => $agentNode->id,
        ]);
        // Agent -> DoWhile
        WorkflowEdge::factory()->create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $agentNode->id,
            'target_node_id' => $doWhile->id,
        ]);
        // DoWhile -> LoopBody (loop body)
        WorkflowEdge::factory()->create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $doWhile->id,
            'target_node_id' => $loopBody->id,
            'is_default' => false,
        ]);
        // LoopBody -> DoWhile (back-edge)
        WorkflowEdge::factory()->create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $loopBody->id,
            'target_node_id' => $doWhile->id,
            'is_default' => false,
        ]);
        // DoWhile -> End (exit)
        WorkflowEdge::factory()->create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $doWhile->id,
            'target_node_id' => $end->id,
            'is_default' => true,
        ]);

        $validator = app(GraphValidator::class);
        $validator->validate($workflow);
        $warnings = $validator->getWarnings();

        // The back-edge (LoopBody -> DoWhile) should be excluded from type checks
        $backEdgeWarnings = array_filter($warnings, fn ($w) => ($w['type'] ?? '') === 'data_type_incompatible'
            && ($w['source_node_id'] ?? '') === $loopBody->id
            && ($w['target_node_id'] ?? '') === $doWhile->id,
        );
        $this->assertEmpty($backEdgeWarnings, 'DoWhile back-edges should be excluded from type compatibility checks');
    }

    public function test_custom_schema_overrides_default(): void
    {
        // Agent with custom output_schema type 'array' -> DynamicFork (input: array) — compatible via override
        $workflow = Workflow::factory()->create();
        $agent = Agent::factory()->create(['team_id' => $workflow->team_id]);

        $start = WorkflowNode::factory()->start()->create(['workflow_id' => $workflow->id]);
        $agentNode = WorkflowNode::factory()->create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::Agent,
            'label' => 'AgentWithArrayOutput',
            'agent_id' => $agent->id,
            'config' => ['output_schema' => ['type' => 'array']],
        ]);
        $fork = WorkflowNode::factory()->create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::DynamicFork,
            'label' => 'ForkNode',
            'config' => ['fork_source' => 'items'],
        ]);
        $end = WorkflowNode::factory()->end()->create(['workflow_id' => $workflow->id]);

        WorkflowEdge::factory()->create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $start->id,
            'target_node_id' => $agentNode->id,
        ]);
        WorkflowEdge::factory()->create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $agentNode->id,
            'target_node_id' => $fork->id,
        ]);
        WorkflowEdge::factory()->create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $fork->id,
            'target_node_id' => $end->id,
        ]);

        $validator = app(GraphValidator::class);
        $validator->validate($workflow);
        $warnings = $validator->getWarnings();

        $typeWarnings = array_filter($warnings, fn ($w) => $w['type'] === 'data_type_incompatible'
            && ($w['source_node_id'] ?? '') === $agentNode->id
            && ($w['target_node_id'] ?? '') === $fork->id,
        );
        $this->assertEmpty($typeWarnings, 'Custom output_schema override should make Agent->DynamicFork compatible');
    }

    public function test_warnings_do_not_block_activation(): void
    {
        // Create a workflow with type incompatibility — should still be valid (warnings only)
        $workflow = Workflow::factory()->create();
        $agent = Agent::factory()->create(['team_id' => $workflow->team_id]);

        $start = WorkflowNode::factory()->start()->create(['workflow_id' => $workflow->id]);
        $agentNode = WorkflowNode::factory()->create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::Agent,
            'label' => 'AgentNode',
            'agent_id' => $agent->id,
        ]);
        $fork = WorkflowNode::factory()->create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::DynamicFork,
            'label' => 'ForkNode',
            'config' => ['fork_source' => 'items'],
        ]);
        $end = WorkflowNode::factory()->end()->create(['workflow_id' => $workflow->id]);

        WorkflowEdge::factory()->create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $start->id,
            'target_node_id' => $agentNode->id,
        ]);
        WorkflowEdge::factory()->create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $agentNode->id,
            'target_node_id' => $fork->id,
        ]);
        WorkflowEdge::factory()->create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $fork->id,
            'target_node_id' => $end->id,
        ]);

        $action = app(ValidateWorkflowGraphAction::class);
        $result = $action->execute($workflow, activateIfValid: true);

        $this->assertTrue($result['valid'], 'Workflow should be valid despite type warnings');
        $this->assertNotEmpty($result['warnings'], 'Should have type incompatibility warnings');
        $this->assertTrue($result['activated'], 'Workflow should still be activated with warnings');
    }

    /**
     * Build a simple linear workflow: Start -> $middleType1 -> $middleType2 -> End
     */
    private function buildLinearWorkflow(WorkflowNodeType $middleType1, WorkflowNodeType $middleType2): Workflow
    {
        $workflow = Workflow::factory()->create();
        $agent = Agent::factory()->create(['team_id' => $workflow->team_id]);

        $start = WorkflowNode::factory()->start()->create(['workflow_id' => $workflow->id]);

        $node1Config = [];
        if ($middleType1 === WorkflowNodeType::HumanTask) {
            $node1Config = ['form_schema' => ['fields' => []]];
        }

        $node1 = WorkflowNode::factory()->create([
            'workflow_id' => $workflow->id,
            'type' => $middleType1,
            'label' => 'Node1',
            'agent_id' => $middleType1->requiresAgent() ? $agent->id : null,
            'config' => $node1Config,
        ]);

        $node2Config = [];
        if ($middleType2 === WorkflowNodeType::HumanTask) {
            $node2Config = ['form_schema' => ['fields' => []]];
        }

        $node2 = WorkflowNode::factory()->create([
            'workflow_id' => $workflow->id,
            'type' => $middleType2,
            'label' => 'Node2',
            'agent_id' => $middleType2->requiresAgent() ? $agent->id : null,
            'config' => $node2Config,
        ]);
        $end = WorkflowNode::factory()->end()->create(['workflow_id' => $workflow->id]);

        WorkflowEdge::factory()->create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $start->id,
            'target_node_id' => $node1->id,
        ]);
        WorkflowEdge::factory()->create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $node1->id,
            'target_node_id' => $node2->id,
        ]);
        WorkflowEdge::factory()->create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $node2->id,
            'target_node_id' => $end->id,
        ]);

        return $workflow;
    }
}
