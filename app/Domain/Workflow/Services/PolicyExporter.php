<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Workflow\Models\Workflow;

/**
 * Exports a workflow's governance policy as a structured JSON document.
 *
 * The exported policy captures approval gates, budget limits, and tool
 * restrictions so the workflow can be reviewed and enforced externally
 * (e.g. committed to a GitOps repository alongside infrastructure policy).
 */
class PolicyExporter
{
    /**
     * Export the workflow governance policy as JSON.
     *
     * The document follows the FleetQ Policy-as-Code schema:
     *   apiVersion: fleetq.io/v1
     *   kind: WorkflowPolicy
     */
    public function export(Workflow $workflow): string
    {
        // Eager-load nodes once to avoid N+1 queries across the extract* methods.
        $workflow->loadMissing('nodes');

        $policy = [
            'apiVersion' => 'fleetq.io/v1',
            'kind' => 'WorkflowPolicy',
            'metadata' => [
                'workflow_id' => $workflow->id,
                'name' => $workflow->name,
                'exported_at' => now()->toIso8601String(),
            ],
            'approval_gates' => $this->extractApprovalGates($workflow),
            'budget_limits' => $this->extractBudgetLimits($workflow),
            'tool_restrictions' => $this->extractToolRestrictions($workflow),
            'data_classification' => 'internal',
        ];

        return json_encode($policy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Extract all human_task nodes as approval gate definitions.
     *
     * @return list<array{node_id: string, label: string, timeout_hours: int}>
     */
    private function extractApprovalGates(Workflow $workflow): array
    {
        return $workflow->nodes
            ->where('type', 'human_task')
            ->map(fn ($node) => [
                'node_id' => $node->id,
                'label' => $node->label ?? 'Human Task',
                'timeout_hours' => $node->config['timeout_hours'] ?? 24,
            ])
            ->values()
            ->toArray();
    }

    /**
     * Extract budget limit configuration from the workflow settings.
     *
     * @return array{max_credits_per_run: int|null, alert_threshold_pct: int}
     */
    private function extractBudgetLimits(Workflow $workflow): array
    {
        return [
            'max_credits_per_run' => $workflow->config['max_budget_credits'] ?? null,
            'alert_threshold_pct' => 80,
        ];
    }

    /**
     * Collect all allowed_tool_ids from agent nodes across the workflow.
     *
     * @return array{allowed_tool_ids: list<string>}
     */
    private function extractToolRestrictions(Workflow $workflow): array
    {
        $allowedToolIds = [];

        foreach ($workflow->nodes->where('type', 'agent') as $node) {
            $toolIds = $node->config['allowed_tool_ids'] ?? [];
            $allowedToolIds = array_merge($allowedToolIds, $toolIds);
        }

        return [
            'allowed_tool_ids' => array_values(array_unique($allowedToolIds)),
        ];
    }
}
