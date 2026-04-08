<?php

namespace Tests\Unit\Domain\Workflow;

use App\Domain\Workflow\Services\WorkflowGraphAnalyzer;
use PHPUnit\Framework\TestCase;

class WorkflowNodePriorityTest extends TestCase
{
    public function test_node_with_more_descendants_gets_higher_priority(): void
    {
        // Graph: A -> B -> C -> End
        //        D -> End
        $adjacency = [
            'A' => ['B'],
            'B' => ['C'],
            'C' => ['end'],
            'D' => ['end'],
            'end' => [],
        ];

        $nodeMap = [
            'A' => ['id' => 'A', 'type' => 'agent'],
            'B' => ['id' => 'B', 'type' => 'agent'],
            'C' => ['id' => 'C', 'type' => 'agent'],
            'D' => ['id' => 'D', 'type' => 'agent'],
            'end' => ['id' => 'end', 'type' => 'end'],
        ];

        $priorities = $this->invokeCalculateNodePriorities(['A', 'D'], $adjacency, $nodeMap);

        // A has 3 descendants (B, C, end), D has 1 (end)
        $this->assertGreaterThan($priorities['D'], $priorities['A']);
    }

    public function test_human_task_gets_type_weight_bonus(): void
    {
        // Both nodes have same graph position
        $adjacency = [
            'agent_node' => ['end'],
            'human_node' => ['end'],
            'end' => [],
        ];

        $nodeMap = [
            'agent_node' => ['id' => 'agent_node', 'type' => 'agent'],
            'human_node' => ['id' => 'human_node', 'type' => 'human_task'],
            'end' => ['id' => 'end', 'type' => 'end'],
        ];

        $priorities = $this->invokeCalculateNodePriorities(['agent_node', 'human_node'], $adjacency, $nodeMap);

        // human_task type weight (3) > agent type weight (1)
        $this->assertGreaterThan($priorities['agent_node'], $priorities['human_node']);
    }

    public function test_crew_node_priority_between_agent_and_human(): void
    {
        $adjacency = [
            'agent' => ['end'],
            'crew' => ['end'],
            'human' => ['end'],
            'end' => [],
        ];

        $nodeMap = [
            'agent' => ['id' => 'agent', 'type' => 'agent'],
            'crew' => ['id' => 'crew', 'type' => 'crew'],
            'human' => ['id' => 'human', 'type' => 'human_task'],
            'end' => ['id' => 'end', 'type' => 'end'],
        ];

        $priorities = $this->invokeCalculateNodePriorities(['agent', 'crew', 'human'], $adjacency, $nodeMap);

        $this->assertGreaterThan($priorities['agent'], $priorities['crew']);
        $this->assertGreaterThan($priorities['crew'], $priorities['human']);
    }

    public function test_single_linear_path_has_no_ordering_effect(): void
    {
        // Only one node to dispatch, priority doesn't matter
        $adjacency = [
            'A' => ['end'],
            'end' => [],
        ];
        $nodeMap = [
            'A' => ['id' => 'A', 'type' => 'agent'],
            'end' => ['id' => 'end', 'type' => 'end'],
        ];

        $priorities = $this->invokeCalculateNodePriorities(['A'], $adjacency, $nodeMap);

        $this->assertCount(1, $priorities);
        $this->assertArrayHasKey('A', $priorities);
    }

    public function test_descendant_counting_handles_cycles(): void
    {
        // Graph with a cycle: A -> B -> C -> A (back edge)
        $adjacency = [
            'A' => ['B'],
            'B' => ['C'],
            'C' => ['A', 'end'], // back-edge to A
            'end' => [],
        ];

        $nodeMap = [
            'A' => ['id' => 'A', 'type' => 'agent'],
            'B' => ['id' => 'B', 'type' => 'agent'],
            'C' => ['id' => 'C', 'type' => 'agent'],
            'end' => ['id' => 'end', 'type' => 'end'],
        ];

        // Should not infinite loop — visited set prevents it
        $priorities = $this->invokeCalculateNodePriorities(['A'], $adjacency, $nodeMap);

        $this->assertArrayHasKey('A', $priorities);
        $this->assertIsInt($priorities['A']);
    }

    public function test_critical_path_depth_increases_priority(): void
    {
        // A -> B -> C -> End (depth 3)
        // D -> End (depth 1)
        // Both have same number of immediate successors
        $adjacency = [
            'A' => ['B'],
            'B' => ['C'],
            'C' => ['end'],
            'D' => ['end'],
            'end' => [],
        ];

        $nodeMap = [
            'A' => ['id' => 'A', 'type' => 'agent'],
            'B' => ['id' => 'B', 'type' => 'agent'],
            'C' => ['id' => 'C', 'type' => 'agent'],
            'D' => ['id' => 'D', 'type' => 'agent'],
            'end' => ['id' => 'end', 'type' => 'end'],
        ];

        $priorities = $this->invokeCalculateNodePriorities(['A', 'D'], $adjacency, $nodeMap);

        // A has longer critical path depth
        $this->assertGreaterThan($priorities['D'], $priorities['A']);
    }

    private function invokeCalculateNodePriorities(array $nodeIds, array $adjacency, array $nodeMap): array
    {
        return WorkflowGraphAnalyzer::calculateNodePriorities($nodeIds, $adjacency, $nodeMap);
    }
}
