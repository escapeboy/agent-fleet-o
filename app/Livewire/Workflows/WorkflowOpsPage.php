<?php

namespace App\Livewire\Workflows;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Workflow\Models\WorkflowNode;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Compensation (saga) ops view.
 *
 * Compensation runs are NOT persisted as their own queryable records — the
 * RunCompensationChainAction only fires CompensationStarted/CompensationCompleted
 * events and dispatches ExecuteCompensationJob (which logs but writes no row).
 *
 * This page therefore reconstructs compensation activity from durable data:
 * failed workflow-run experiments whose completed steps had a compensation node
 * defined (i.e. steps that would have been rolled back). It is strictly read-only.
 */
class WorkflowOpsPage extends Component
{
    use WithPagination;

    /**
     * For each failed workflow experiment, count completed steps whose workflow
     * node has a compensation_node_id (the steps eligible for saga rollback).
     *
     * @return Collection<int, array{experiment: Experiment, compensated_count: int}>
     */
    private function compensationRuns(): Collection
    {
        // Only failed workflow-run experiments can trigger compensation.
        $experiments = Experiment::query()
            ->whereNotNull('workflow_id')
            ->whereIn('status', ['execution_failed', 'building_failed', 'planning_failed', 'scoring_failed'])
            ->with('workflow:id,name')
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();

        if ($experiments->isEmpty()) {
            return collect();
        }

        return $experiments
            ->map(function (Experiment $experiment): array {
                $completedNodeIds = PlaybookStep::query()
                    ->where('experiment_id', $experiment->id)
                    ->where('status', 'completed')
                    ->whereNotNull('workflow_node_id')
                    ->pluck('workflow_node_id');

                if ($completedNodeIds->isEmpty()) {
                    return ['experiment' => $experiment, 'compensated_count' => 0];
                }

                $compensatedCount = WorkflowNode::withoutGlobalScopes()
                    ->whereHas('workflow', fn ($q) => $q->where('team_id', $experiment->team_id))
                    ->whereIn('id', $completedNodeIds)
                    ->whereNotNull('compensation_node_id')
                    ->count();

                return ['experiment' => $experiment, 'compensated_count' => $compensatedCount];
            })
            ->filter(fn (array $row): bool => $row['compensated_count'] > 0)
            ->values();
    }

    public function render()
    {
        return view('livewire.workflows.workflow-ops-page', [
            'runs' => $this->compensationRuns(),
        ])->layout('layouts.app', ['header' => 'Workflow Compensation']);
    }
}
