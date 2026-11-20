<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Events\WorkflowSaved;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowEdge;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Domain\Workflow\Models\WorkflowVersion;
use Illuminate\Support\Facades\DB;

class UpdateWorkflowAction
{
    /**
     * Update a workflow's metadata and/or its graph.
     *
     * If the workflow is active (not draft), incrementing the version.
     * Nodes and edges are replaced wholesale when provided.
     */
    public function execute(
        Workflow $workflow,
        ?string $name = null,
        ?string $description = null,
        ?int $maxLoopIterations = null,
        ?array $nodes = null,
        ?array $edges = null,
        ?array $settings = null,
        ?int $budgetCapCredits = null,
        bool $clearBudgetCap = false,
        ?bool $mcpExposed = null,
        ?string $mcpToolName = null,
        ?string $mcpExecutionMode = null,
    ): Workflow {
        $workflow = DB::transaction(function () use ($workflow, $name, $description, $maxLoopIterations, $nodes, $edges, $settings, $budgetCapCredits, $clearBudgetCap, $mcpExposed, $mcpToolName, $mcpExecutionMode) {
            $changes = [];

            if ($name !== null && $name !== $workflow->name) {
                $changes['name'] = $name;
                $workflow->name = $name;
            }

            if ($description !== null) {
                $changes['description'] = 'updated';
                $workflow->description = $description;
            }

            if ($maxLoopIterations !== null) {
                $changes['max_loop_iterations'] = $maxLoopIterations;
                $workflow->max_loop_iterations = $maxLoopIterations;
            }

            if ($settings !== null) {
                $workflow->settings = array_merge($workflow->settings ?? [], $settings);
                $changes['settings'] = 'updated';
            }

            if ($clearBudgetCap) {
                $workflow->budget_cap_credits = null;
                $changes['budget_cap_credits'] = null;
            } elseif ($budgetCapCredits !== null) {
                $workflow->budget_cap_credits = $budgetCapCredits;
                $changes['budget_cap_credits'] = $budgetCapCredits;
            }

            if ($mcpExposed !== null) {
                $workflow->mcp_exposed = $mcpExposed;
                $changes['mcp_exposed'] = $mcpExposed;
            }

            if ($mcpToolName !== null) {
                $workflow->mcp_tool_name = $mcpToolName ?: null;
                $changes['mcp_tool_name'] = $mcpToolName ?: null;
            }

            if ($mcpExecutionMode !== null) {
                $workflow->mcp_execution_mode = $mcpExecutionMode;
                $changes['mcp_execution_mode'] = $mcpExecutionMode;
            }

            // If graph is being updated and workflow is active, bump version
            if ($nodes !== null && $workflow->isActive()) {
                // Keep an immutable copy of the graph being replaced.
                $snapshot = $this->snapshotGraph($workflow);
                $changes['snapshot_version'] = $snapshot->version;

                $workflow->version = $workflow->version + 1;
                $changes['version'] = $workflow->version;
            }

            $workflow->save();

            // Replace graph if nodes provided
            if ($nodes !== null) {
                $this->replaceGraph($workflow, $nodes, $edges ?? []);
                $changes['graph'] = 'replaced';
            }

            if (! empty($changes)) {
                activity()
                    ->performedOn($workflow)
                    ->withProperties($changes)
                    ->log('workflow.updated');
            }

            return $workflow->fresh();
        });

        // Trendshift top-5 build #5: notify Git-sync listener (one-way YAML push).
        WorkflowSaved::dispatch($workflow);

        return $workflow;
    }

    /**
     * Store the workflow's current graph under its current version number,
     * in the shape replaceGraph() accepts so it can be restored verbatim.
     */
    private function snapshotGraph(Workflow $workflow): WorkflowVersion
    {
        $nodes = WorkflowNode::query()
            ->where('workflow_id', $workflow->id)
            ->orderBy('order')
            ->get()
            ->map(fn (WorkflowNode $node) => [
                'id' => $node->id,
                'type' => $this->nodeTypeValue($node->getAttribute('type')),
                'label' => $node->label,
                'agent_id' => $node->agent_id,
                'skill_id' => $node->skill_id,
                'crew_id' => $node->crew_id,
                'sub_workflow_id' => $node->sub_workflow_id,
                'config' => $node->config ?? [],
                'position_x' => $node->position_x,
                'position_y' => $node->position_y,
                'order' => $node->order,
            ])->values()->all();

        $edges = WorkflowEdge::query()
            ->where('workflow_id', $workflow->id)
            ->get()
            ->map(fn (WorkflowEdge $edge) => [
                'source_node_id' => $edge->source_node_id,
                'target_node_id' => $edge->target_node_id,
                'condition' => $edge->condition,
                'label' => $edge->label,
                'is_default' => $edge->is_default,
                'sort_order' => $edge->sort_order,
            ])->values()->all();

        return WorkflowVersion::withoutGlobalScopes()->firstOrCreate(
            ['workflow_id' => $workflow->id, 'version' => $workflow->version],
            [
                'team_id' => $workflow->team_id,
                'graph' => ['nodes' => $nodes, 'edges' => $edges],
                'created_by' => auth()->id(),
                'created_at' => now(),
            ],
        );
    }

    private function nodeTypeValue(mixed $type): mixed
    {
        return $type instanceof WorkflowNodeType ? $type->value : $type;
    }

    private function replaceGraph(Workflow $workflow, array $nodes, array $edges): void
    {
        // Delete existing graph
        $workflow->edges()->delete();
        $workflow->nodes()->delete();

        // Create new nodes
        $createdNodes = [];

        foreach ($nodes as $index => $nodeData) {
            $createdNodes[$nodeData['id'] ?? $index] = WorkflowNode::create([
                'workflow_id' => $workflow->id,
                'agent_id' => $nodeData['agent_id'] ?? null,
                'skill_id' => $nodeData['skill_id'] ?? null,
                'crew_id' => $nodeData['crew_id'] ?? null,
                'sub_workflow_id' => $nodeData['sub_workflow_id'] ?? null,
                'type' => $nodeData['type'],
                'label' => $nodeData['label'],
                'position_x' => $nodeData['position_x'] ?? 0,
                'position_y' => $nodeData['position_y'] ?? 0,
                'config' => $nodeData['config'] ?? [],
                'order' => $nodeData['order'] ?? $index,
            ]);
        }

        // Create new edges
        foreach ($edges as $edgeData) {
            $sourceNode = $createdNodes[$edgeData['source_node_id'] ?? ''] ?? null;
            $targetNode = $createdNodes[$edgeData['target_node_id'] ?? ''] ?? null;

            if (! $sourceNode || ! $targetNode) {
                continue;
            }

            WorkflowEdge::create([
                'workflow_id' => $workflow->id,
                'source_node_id' => $sourceNode->id,
                'target_node_id' => $targetNode->id,
                'condition' => $edgeData['condition'] ?? null,
                'label' => $edgeData['label'] ?? null,
                'is_default' => $edgeData['is_default'] ?? false,
                'sort_order' => $edgeData['sort_order'] ?? 0,
            ]);
        }
    }
}
