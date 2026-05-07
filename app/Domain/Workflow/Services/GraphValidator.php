<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowNode;
use Illuminate\Support\Collection;

class GraphValidator
{
    private Collection $nodes;

    private Collection $edges;

    private array $errors = [];

    private array $warnings = [];

    /**
     * Validate the workflow graph.
     *
     * @return array<array{type: string, message: string, node_id?: string}>
     */
    public function validate(Workflow $workflow): array
    {
        $this->errors = [];
        $this->warnings = [];
        $this->nodes = $workflow->nodes()->get();
        $this->edges = $workflow->edges()->get();

        $this->validateStartNode();
        $this->validateEndNodes();
        $this->validateOrphanNodes();
        $this->validateReachability();
        $this->validateConditionalNodes();
        $this->validateSwitchNodes();
        $this->validateDynamicForkNodes();
        $this->validateDoWhileNodes();
        $this->validateHumanTaskNodes();
        $this->validateLoopExits();
        $this->validateAgentNodes();
        $this->validateCrewNodes();
        $this->validateTimeGateNodes();
        $this->validateMergeNodes();
        $this->validateActivationModes();
        $this->validateSubWorkflowNodes();
        $this->validateCompensationNodes();
        $this->validateDataTypeCompatibility();

        return $this->errors;
    }

    /**
     * Return any warnings collected during the last validate() call.
     *
     * @return array<array{type: string, message: string, edge_id?: string, source_node_id?: string, target_node_id?: string}>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function isValid(Workflow $workflow): bool
    {
        return empty($this->validate($workflow));
    }

    private function validateStartNode(): void
    {
        $startNodes = $this->nodes->where('type', WorkflowNodeType::Start);

        if ($startNodes->isEmpty()) {
            $this->errors[] = [
                'type' => 'missing_start',
                'message' => 'Workflow must have exactly one Start node.',
            ];
        } elseif ($startNodes->count() > 1) {
            $this->errors[] = [
                'type' => 'multiple_starts',
                'message' => 'Workflow must have exactly one Start node, found '.$startNodes->count().'.',
                'node_ids' => $startNodes->pluck('id')->toArray(),
            ];
        }
    }

    private function validateEndNodes(): void
    {
        $endNodes = $this->nodes->where('type', WorkflowNodeType::End);

        if ($endNodes->isEmpty()) {
            $this->errors[] = [
                'type' => 'missing_end',
                'message' => 'Workflow must have at least one End node.',
            ];
        }
    }

    private function validateOrphanNodes(): void
    {
        foreach ($this->nodes as $node) {
            if ($node->type === WorkflowNodeType::Start) {
                continue;
            }

            $hasIncoming = $this->edges->where('target_node_id', $node->id)->isNotEmpty();

            if (! $hasIncoming) {
                $this->errors[] = [
                    'type' => 'orphan_node',
                    'message' => "Node '{$node->label}' has no incoming edges and is unreachable.",
                    'node_id' => $node->id,
                ];
            }
        }

        foreach ($this->nodes as $node) {
            if ($node->type === WorkflowNodeType::End) {
                continue;
            }

            $hasOutgoing = $this->edges->where('source_node_id', $node->id)->isNotEmpty();

            if (! $hasOutgoing) {
                $this->errors[] = [
                    'type' => 'dead_end_node',
                    'message' => "Node '{$node->label}' has no outgoing edges.",
                    'node_id' => $node->id,
                ];
            }
        }
    }

    private function validateReachability(): void
    {
        $startNode = $this->nodes->firstWhere('type', WorkflowNodeType::Start);

        if (! $startNode) {
            return;
        }

        $reachable = $this->findReachableNodes($startNode->id);
        $unreachable = $this->nodes->reject(fn (WorkflowNode $n) => $reachable->contains($n->id));

        foreach ($unreachable as $node) {
            $this->errors[] = [
                'type' => 'unreachable_node',
                'message' => "Node '{$node->label}' is not reachable from the Start node.",
                'node_id' => $node->id,
            ];
        }
    }

    private function findReachableNodes(string $fromNodeId): Collection
    {
        $visited = collect([$fromNodeId]);
        $queue = [$fromNodeId];

        while (! empty($queue)) {
            $currentId = array_shift($queue);

            $outgoing = $this->edges->where('source_node_id', $currentId);

            foreach ($outgoing as $edge) {
                if (! $visited->contains($edge->target_node_id)) {
                    $visited->push($edge->target_node_id);
                    $queue[] = $edge->target_node_id;
                }
            }
        }

        return $visited;
    }

    private function validateConditionalNodes(): void
    {
        $conditionalNodes = $this->nodes->where('type', WorkflowNodeType::Conditional);

        foreach ($conditionalNodes as $node) {
            $outgoing = $this->edges->where('source_node_id', $node->id);

            if ($outgoing->count() < 2) {
                $this->errors[] = [
                    'type' => 'conditional_insufficient_edges',
                    'message' => "Conditional node '{$node->label}' must have at least 2 outgoing edges.",
                    'node_id' => $node->id,
                ];
            }

            $hasDefault = $outgoing->where('is_default', true)->isNotEmpty();

            if (! $hasDefault && $outgoing->isNotEmpty()) {
                $this->errors[] = [
                    'type' => 'conditional_no_default',
                    'message' => "Conditional node '{$node->label}' must have a default (fallback) edge.",
                    'node_id' => $node->id,
                ];
            }
        }
    }

    private function validateSwitchNodes(): void
    {
        $switchNodes = $this->nodes->where('type', WorkflowNodeType::Switch);

        foreach ($switchNodes as $node) {
            $outgoing = $this->edges->where('source_node_id', $node->id);

            if ($outgoing->count() < 2) {
                $this->errors[] = [
                    'type' => 'switch_insufficient_edges',
                    'message' => "Switch node '{$node->label}' must have at least 2 outgoing edges.",
                    'node_id' => $node->id,
                ];
            }

            $hasDefault = $outgoing->where('is_default', true)->isNotEmpty();
            if (! $hasDefault && $outgoing->isNotEmpty()) {
                $this->errors[] = [
                    'type' => 'switch_no_default',
                    'message' => "Switch node '{$node->label}' must have a default edge.",
                    'node_id' => $node->id,
                ];
            }

            if (! $node->expression) {
                $this->errors[] = [
                    'type' => 'switch_no_expression',
                    'message' => "Switch node '{$node->label}' requires an expression field.",
                    'node_id' => $node->id,
                ];
            }

            // Each non-default edge must have a case_value
            $nonDefault = $outgoing->where('is_default', '!=', true);
            foreach ($nonDefault as $edge) {
                if (empty($edge->case_value)) {
                    $this->errors[] = [
                        'type' => 'switch_edge_no_case_value',
                        'message' => "Switch node '{$node->label}' has an edge without a case_value.",
                        'node_id' => $node->id,
                        'edge_id' => $edge->id,
                    ];
                }
            }
        }
    }

    private function validateDynamicForkNodes(): void
    {
        $forkNodes = $this->nodes->where('type', WorkflowNodeType::DynamicFork);

        foreach ($forkNodes as $node) {
            $outgoing = $this->edges->where('source_node_id', $node->id);

            if ($outgoing->count() !== 1) {
                $this->errors[] = [
                    'type' => 'dynamic_fork_wrong_edges',
                    'message' => "Dynamic Fork node '{$node->label}' must have exactly 1 outgoing edge (the template path).",
                    'node_id' => $node->id,
                ];
            }

            $config = is_string($node->config) ? json_decode($node->config, true) : ($node->config ?? []);

            if (empty($config['fork_source'])) {
                $this->errors[] = [
                    'type' => 'dynamic_fork_no_source',
                    'message' => "Dynamic Fork node '{$node->label}' requires a 'fork_source' in config specifying the array field to iterate.",
                    'node_id' => $node->id,
                ];
            }
        }
    }

    private function validateDoWhileNodes(): void
    {
        $doWhileNodes = $this->nodes->where('type', WorkflowNodeType::DoWhile);

        foreach ($doWhileNodes as $node) {
            $outgoing = $this->edges->where('source_node_id', $node->id);

            if ($outgoing->count() < 2) {
                $this->errors[] = [
                    'type' => 'do_while_insufficient_edges',
                    'message' => "Do While node '{$node->label}' must have at least 2 outgoing edges (loop body + exit).",
                    'node_id' => $node->id,
                ];
            }

            $config = is_string($node->config) ? json_decode($node->config, true) : ($node->config ?? []);

            if (empty($config['break_condition'])) {
                $this->errors[] = [
                    'type' => 'do_while_no_break_condition',
                    'message' => "Do While node '{$node->label}' requires a 'break_condition' in config.",
                    'node_id' => $node->id,
                ];
            }
        }
    }

    private function validateHumanTaskNodes(): void
    {
        $humanTaskNodes = $this->nodes->where('type', WorkflowNodeType::HumanTask);

        foreach ($humanTaskNodes as $node) {
            $config = is_string($node->config) ? json_decode($node->config, true) : ($node->config ?? []);

            if (empty($config['form_schema'])) {
                $this->errors[] = [
                    'type' => 'human_task_no_form_schema',
                    'message' => "Human Task node '{$node->label}' requires a 'form_schema' in config.",
                    'node_id' => $node->id,
                ];
            }
        }
    }

    private function validateLoopExits(): void
    {
        $startNode = $this->nodes->firstWhere('type', WorkflowNodeType::Start);

        if (! $startNode) {
            return;
        }

        // Detect nodes that are part of cycles
        $cycleNodes = $this->detectCycleNodes();

        foreach ($cycleNodes as $nodeId) {
            $node = $this->nodes->firstWhere('id', $nodeId);

            if (! $node) {
                continue;
            }

            // Check that at least one outgoing edge from a cycle node leads outside the cycle
            $outgoing = $this->edges->where('source_node_id', $nodeId);
            $hasExit = false;

            foreach ($outgoing as $edge) {
                if (! in_array($edge->target_node_id, $cycleNodes)) {
                    $hasExit = true;
                    break;
                }
            }

            // Also check if incoming edges to this node from outside the cycle provide an exit
            // (the cycle can be exited via conditional logic on any node in the cycle)
            if (! $hasExit) {
                // Check if any node in the cycle has an exit
                foreach ($cycleNodes as $cycleNodeId) {
                    $cycleOutgoing = $this->edges->where('source_node_id', $cycleNodeId);
                    foreach ($cycleOutgoing as $edge) {
                        if (! in_array($edge->target_node_id, $cycleNodes)) {
                            $hasExit = true;
                            break 2;
                        }
                    }
                }
            }

            if (! $hasExit) {
                $this->errors[] = [
                    'type' => 'loop_no_exit',
                    'message' => "Loop containing node '{$node->label}' has no exit path. Add an edge leading outside the loop.",
                    'node_id' => $nodeId,
                ];
                break; // Only report once per cycle
            }
        }
    }

    private function detectCycleNodes(): array
    {
        $adjacency = [];
        foreach ($this->edges as $edge) {
            $adjacency[$edge->source_node_id][] = $edge->target_node_id;
        }

        $cycleNodes = [];
        $visited = [];
        $recursionStack = [];

        foreach ($this->nodes as $node) {
            if (! isset($visited[$node->id])) {
                $this->dfsDetectCycles($node->id, $adjacency, $visited, $recursionStack, $cycleNodes);
            }
        }

        return array_unique($cycleNodes);
    }

    private function dfsDetectCycles(
        string $nodeId,
        array $adjacency,
        array &$visited,
        array &$recursionStack,
        array &$cycleNodes,
    ): void {
        $visited[$nodeId] = true;
        $recursionStack[$nodeId] = true;

        foreach ($adjacency[$nodeId] ?? [] as $neighbor) {
            if (! isset($visited[$neighbor])) {
                $this->dfsDetectCycles($neighbor, $adjacency, $visited, $recursionStack, $cycleNodes);
            } elseif (isset($recursionStack[$neighbor])) {
                // Found a cycle — collect all nodes in the recursion stack
                $cycleNodes[] = $neighbor;
                $cycleNodes[] = $nodeId;
            }
        }

        unset($recursionStack[$nodeId]);
    }

    private function validateAgentNodes(): void
    {
        $agentNodes = $this->nodes->where('type', WorkflowNodeType::Agent);

        foreach ($agentNodes as $node) {
            if (! $node->agent_id) {
                $this->errors[] = [
                    'type' => 'agent_node_no_agent',
                    'message' => "Agent node '{$node->label}' has no agent assigned.",
                    'node_id' => $node->id,
                ];
            }

            // Warn if output_schema is set but not a valid JSON Schema object
            $schema = $node->config['output_schema'] ?? null;
            if ($schema !== null) {
                if (! is_array($schema) || ! isset($schema['type'])) {
                    $this->warnings[] = [
                        'type' => 'agent_invalid_output_schema',
                        'message' => "Agent node '{$node->label}' has an output_schema but it is missing a 'type' field. Schema may not be enforced correctly.",
                        'node_id' => $node->id,
                    ];
                }
            }
        }
    }

    private function validateCrewNodes(): void
    {
        $crewNodes = $this->nodes->where('type', WorkflowNodeType::Crew);

        foreach ($crewNodes as $node) {
            if (! $node->crew_id) {
                $this->errors[] = [
                    'type' => 'crew_node_no_crew',
                    'message' => "Crew node '{$node->label}' has no crew assigned.",
                    'node_id' => $node->id,
                ];
            }
        }
    }

    private function validateTimeGateNodes(): void
    {
        $timeGateNodes = $this->nodes->where('type', WorkflowNodeType::TimeGate);

        foreach ($timeGateNodes as $node) {
            $config = is_string($node->config) ? json_decode($node->config, true) : ($node->config ?? []);

            if (empty($config['delay_seconds']) && empty($config['delay_until'])) {
                $this->errors[] = [
                    'type' => 'time_gate_no_delay',
                    'message' => "Time Gate node '{$node->label}' requires either 'delay_seconds' or 'delay_until' in config.",
                    'node_id' => $node->id,
                ];
            }
        }
    }

    private function validateMergeNodes(): void
    {
        $mergeNodes = $this->nodes->where('type', WorkflowNodeType::Merge);

        foreach ($mergeNodes as $node) {
            $incoming = $this->edges->where('target_node_id', $node->id);

            if ($incoming->count() < 2) {
                $this->errors[] = [
                    'type' => 'merge_insufficient_incoming',
                    'message' => "Merge node '{$node->label}' should have at least 2 incoming edges to be meaningful.",
                    'node_id' => $node->id,
                ];
            }

            $outgoing = $this->edges->where('source_node_id', $node->id);

            if ($outgoing->count() !== 1) {
                $this->errors[] = [
                    'type' => 'merge_wrong_outgoing',
                    'message' => "Merge node '{$node->label}' must have exactly 1 outgoing edge.",
                    'node_id' => $node->id,
                ];
            }

            // Validate activation_mode settings
            $this->validateActivationMode($node, $incoming->count());
        }
    }

    private function validateActivationMode(WorkflowNode $node, int $incomingCount): void
    {
        $mode = $node->activation_mode->value ?? 'all';
        $validModes = ['all', 'any', 'n_of_m'];

        if (! in_array($mode, $validModes, true)) {
            $this->errors[] = [
                'type' => 'invalid_activation_mode',
                'message' => "Node '{$node->label}' has invalid activation_mode '{$mode}'. Must be one of: all, any, n_of_m.",
                'node_id' => $node->id,
            ];

            return;
        }

        if ($mode === 'n_of_m') {
            $threshold = $node->activation_threshold;
            if ($threshold === null || $threshold < 1) {
                $this->errors[] = [
                    'type' => 'missing_activation_threshold',
                    'message' => "Node '{$node->label}' uses n_of_m activation mode but has no valid activation_threshold (must be >= 1).",
                    'node_id' => $node->id,
                ];
            } elseif ($threshold > $incomingCount) {
                $this->errors[] = [
                    'type' => 'activation_threshold_exceeds_incoming',
                    'message' => "Node '{$node->label}' activation_threshold ({$threshold}) exceeds incoming edge count ({$incomingCount}).",
                    'node_id' => $node->id,
                ];
            }
        }
    }

    /**
     * Validate activation_mode on all non-merge nodes that have incoming edges.
     * (Merge nodes are already validated in validateMergeNodes.)
     */
    private function validateActivationModes(): void
    {
        $nonMergeNodes = $this->nodes->where('type', '!=', WorkflowNodeType::Merge);

        foreach ($nonMergeNodes as $node) {
            $incoming = $this->edges->where('target_node_id', $node->id);
            if ($incoming->count() < 2) {
                continue; // Activation mode is irrelevant with 0-1 incoming edges
            }

            $this->validateActivationMode($node, $incoming->count());
        }
    }

    private function validateSubWorkflowNodes(): void
    {
        $subWorkflowNodes = $this->nodes->where('type', WorkflowNodeType::SubWorkflow);

        foreach ($subWorkflowNodes as $node) {
            if (! $node->sub_workflow_id) {
                $this->errors[] = [
                    'type' => 'sub_workflow_no_workflow',
                    'message' => "Sub-Workflow node '{$node->label}' has no sub-workflow assigned.",
                    'node_id' => $node->id,
                ];

                continue;
            }

            // Guard against circular references: the sub-workflow must not reference this workflow
            // (shallow check — we don't traverse nested sub-workflows)
            $subWorkflow = Workflow::find($node->sub_workflow_id);
            if ($subWorkflow) {
                $subWorkflowId = $node->sub_workflow_id;
                $parentWorkflowId = $node->workflow_id;
                if ($subWorkflowId === $parentWorkflowId) {
                    $this->errors[] = [
                        'type' => 'sub_workflow_circular_reference',
                        'message' => "Sub-Workflow node '{$node->label}' references its own parent workflow, creating a circular dependency.",
                        'node_id' => $node->id,
                    ];
                }
            }
        }
    }

    /**
     * Validate data type compatibility across all edges.
     *
     * Mismatches are collected as warnings (advisory only, do not block activation).
     * DoWhile back-edges (edges that target a DoWhile node and create a cycle) are skipped.
     * Custom output_schema / input_schema in node config override default port schemas.
     */
    private function validateCompensationNodes(): void
    {
        $nodeIds = $this->nodes->pluck('id')->all();

        foreach ($this->nodes as $node) {
            if (! $node->compensation_node_id) {
                continue;
            }

            // Compensation node must be in the same workflow
            if (! in_array($node->compensation_node_id, $nodeIds, true)) {
                $this->errors[] = [
                    'type' => 'compensation_node_not_in_workflow',
                    'message' => "Node '{$node->label}' references a compensation_node_id that does not belong to this workflow.",
                    'node_id' => $node->id,
                ];

                continue;
            }

            // Compensation nodes cannot have their own compensation node
            $compensationNode = $this->nodes->firstWhere('id', $node->compensation_node_id);
            if ($compensationNode && $compensationNode->compensation_node_id) {
                $this->errors[] = [
                    'type' => 'recursive_compensation',
                    'message' => "Compensation node '{$compensationNode->label}' cannot itself have a compensation node (no recursive saga).",
                    'node_id' => $node->id,
                ];
            }
        }
    }

    private function validateDataTypeCompatibility(): void
    {
        // Build lookup maps
        $nodeMap = [];
        foreach ($this->nodes as $node) {
            $nodeMap[$node->id] = [
                'type' => $node->type,
                'label' => $node->label,
                'config' => is_string($node->config) ? json_decode($node->config, true) : ($node->config ?? []),
            ];
        }

        $edgesByTarget = [];
        foreach ($this->edges as $edge) {
            $edgesByTarget[$edge->target_node_id][] = $edge;
        }

        // Detect DoWhile back-edge targets: a DoWhile node that is reached by one of its own descendants
        $doWhileBackEdgeKeys = $this->detectDoWhileBackEdges($nodeMap);

        foreach ($this->edges as $edge) {
            $edgeKey = $edge->source_node_id.'->'.$edge->target_node_id;

            // Skip DoWhile back-edges
            if (isset($doWhileBackEdgeKeys[$edgeKey])) {
                continue;
            }

            $sourceNode = $nodeMap[$edge->source_node_id] ?? null;
            $targetNode = $nodeMap[$edge->target_node_id] ?? null;

            if (! $sourceNode || ! $targetNode) {
                continue;
            }

            $outputType = $this->resolveOutputType($edge->source_node_id, $sourceNode, $nodeMap, $edgesByTarget);
            $inputType = $this->resolveInputType($targetNode);

            if (! NodeTypeCompatibility::isCompatible($outputType, $inputType)) {
                $this->warnings[] = [
                    'type' => 'data_type_incompatible',
                    'message' => "Type mismatch: node '{$sourceNode['label']}' outputs '{$outputType}' but node '{$targetNode['label']}' expects '{$inputType}'.",
                    'edge_id' => $edge->id,
                    'source_node_id' => $edge->source_node_id,
                    'target_node_id' => $edge->target_node_id,
                ];
            }
        }
    }

    /**
     * Resolve the output type for a source node, considering custom output_schema overrides.
     */
    private function resolveOutputType(string $nodeId, array $nodeData, array $nodeMap, array $edgesByTarget): string
    {
        $config = $nodeData['config'];

        // Custom output_schema override
        if (! empty($config['output_schema']) && is_array($config['output_schema'])) {
            $customType = $config['output_schema']['type'] ?? null;
            if (is_string($customType) && $customType !== '') {
                return $customType;
            }
            // Invalid custom schema — emit warning, fall back to default
            $this->warnings[] = [
                'type' => 'invalid_custom_output_schema',
                'message' => "Node '{$nodeData['label']}' has an invalid output_schema in config; using default port type.",
                'source_node_id' => $nodeId,
            ];
        }

        $schema = $nodeData['type']->portSchema();
        $outputType = $schema['outputs'][0]['type'] ?? 'any';

        if ($outputType === 'passthrough') {
            return NodeTypeCompatibility::resolvePassthroughType($nodeId, $nodeMap, $edgesByTarget);
        }

        return $outputType;
    }

    /**
     * Resolve the input type for a target node, considering custom input_schema overrides.
     */
    private function resolveInputType(array $nodeData): string
    {
        $config = $nodeData['config'];

        // Custom input_schema override
        if (! empty($config['input_schema']) && is_array($config['input_schema'])) {
            $customType = $config['input_schema']['type'] ?? null;
            if (is_string($customType) && $customType !== '') {
                return $customType;
            }
            // Invalid custom schema — emit warning, fall back to default
            $this->warnings[] = [
                'type' => 'invalid_custom_input_schema',
                'message' => "Node '{$nodeData['label']}' has an invalid input_schema in config; using default port type.",
            ];
        }

        $schema = $nodeData['type']->portSchema();

        return $schema['inputs'][0]['type'] ?? 'any';
    }

    /**
     * Detect DoWhile back-edges: edges whose target is a DoWhile node AND that create a cycle
     * (i.e., the source is reachable from the DoWhile node via forward traversal).
     *
     * @return array<string, true> Keys are "source_id->target_id" strings.
     */
    private function detectDoWhileBackEdges(array $nodeMap): array
    {
        $backEdges = [];

        $doWhileNodes = array_filter($nodeMap, fn (array $n) => $n['type'] === WorkflowNodeType::DoWhile);

        if (empty($doWhileNodes)) {
            return [];
        }

        // Build adjacency list for forward traversal
        $adjacency = [];
        foreach ($this->edges as $edge) {
            $adjacency[$edge->source_node_id][] = $edge->target_node_id;
        }

        foreach ($doWhileNodes as $doWhileId => $_) {
            // Find all nodes reachable from this DoWhile node
            $reachable = $this->collectReachable($doWhileId, $adjacency);

            // Any edge that goes FROM a reachable node BACK TO this DoWhile node is a back-edge
            foreach ($this->edges as $edge) {
                if ($edge->target_node_id === $doWhileId && $reachable->contains($edge->source_node_id)) {
                    $backEdges[$edge->source_node_id.'->'.$edge->target_node_id] = true;
                }
            }
        }

        return $backEdges;
    }

    /**
     * Collect all node IDs reachable from a given node via BFS.
     */
    private function collectReachable(string $fromNodeId, array $adjacency): Collection
    {
        $visited = collect();
        $queue = [$fromNodeId];

        while (! empty($queue)) {
            $currentId = array_shift($queue);

            foreach ($adjacency[$currentId] ?? [] as $neighbor) {
                if (! $visited->contains($neighbor)) {
                    $visited->push($neighbor);
                    $queue[] = $neighbor;
                }
            }
        }

        return $visited;
    }
}
