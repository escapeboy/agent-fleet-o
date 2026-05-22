<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Models\Workflow;

class EstimateWorkflowCostAction
{
    /**
     * Estimate the total cost of running a workflow in credits.
     *
     * Accounts for:
     * - Each agent node's cost profile
     * - Loop multiplier (average case: max_iterations / 2)
     * - Does not account for conditional branches (assumes all paths are taken)
     */
    public function execute(Workflow $workflow): int
    {
        $workflow->load(['nodes.agent', 'edges']);

        $totalCredits = 0;
        $loopMultiplier = max(1, (int) ceil($workflow->max_loop_iterations / 2));

        // Detect which nodes are in loops
        $loopNodeIds = $this->detectLoopNodes($workflow);

        foreach ($workflow->nodes as $node) {
            $costPerRun = 0;

            if ($node->type === WorkflowNodeType::Agent && $node->agent) {
                $costPerRun = $this->estimateNodeCost($node);
            } elseif ($node->type === WorkflowNodeType::Llm || $node->type === WorkflowNodeType::ParameterExtractor) {
                // LLM and ParameterExtractor nodes use a direct LLM call — estimate using Haiku pricing
                // (assumes claude-haiku-4-5 unless configured otherwise)
                $config = is_string($node->config) ? json_decode($node->config, true) : ($node->config ?? []);
                $maxTokens = (int) ($config['max_tokens'] ?? 1024);
                // Haiku: ~3 credits input / 15 credits output per 1k tokens
                $costPerRun = (int) ceil((1000 / 1000 * 3) + ($maxTokens / 1000 * 15));
            }
            // TemplateTransform, VariableAggregator, KnowledgeRetrieval, HttpRequest = zero LLM cost

            if ($costPerRun > 0) {
                $multiplier = in_array($node->id, $loopNodeIds) ? $loopMultiplier : 1;
                $totalCredits += $costPerRun * $multiplier;
            }
        }

        $workflow->update(['estimated_cost_credits' => $totalCredits]);

        return $totalCredits;
    }

    private function estimateNodeCost($node): int
    {
        // Use agent's cost profile if available
        $inputCostPer1k = $node->agent->cost_per_1k_input ?? 3;
        $outputCostPer1k = $node->agent->cost_per_1k_output ?? 15;

        // Assume average: 1k input tokens + 0.5k output tokens
        $estimatedInputTokens = 1000;
        $estimatedOutputTokens = 500;

        return (int) ceil(
            ($estimatedInputTokens / 1000 * $inputCostPer1k) +
            ($estimatedOutputTokens / 1000 * $outputCostPer1k),
        );
    }

    private function detectLoopNodes(Workflow $workflow): array
    {
        $nodeIds = $workflow->nodes->pluck('id')->toArray();
        $adjacency = [];

        foreach ($workflow->edges as $edge) {
            $adjacency[$edge->source_node_id][] = $edge->target_node_id;
        }

        // Detect back edges (edges to already-visited nodes in DFS)
        $loopNodes = [];
        $visited = [];
        $recursionStack = [];

        foreach ($nodeIds as $nodeId) {
            if (! isset($visited[$nodeId])) {
                $this->dfs($nodeId, $adjacency, $visited, $recursionStack, $loopNodes);
            }
        }

        return array_unique($loopNodes);
    }

    private function dfs(string $nodeId, array $adjacency, array &$visited, array &$stack, array &$loopNodes): void
    {
        $visited[$nodeId] = true;
        $stack[$nodeId] = true;

        foreach ($adjacency[$nodeId] ?? [] as $neighbor) {
            if (! isset($visited[$neighbor])) {
                $this->dfs($neighbor, $adjacency, $visited, $stack, $loopNodes);
            } elseif (isset($stack[$neighbor])) {
                $loopNodes[] = $neighbor;
                $loopNodes[] = $nodeId;
            }
        }

        unset($stack[$nodeId]);
    }
}
