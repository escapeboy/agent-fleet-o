<?php

namespace App\Livewire\Projects;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectRun;
use Livewire\Component;

class ProjectKanbanPage extends Component
{
    public Project $project;

    public string $viewMode = 'kanban'; // kanban or graph

    /** Kanban columns grouped by status category */
    public static array $columns = [
        'draft' => [
            'label' => 'Draft',
            'color' => 'gray',
            'statuses' => ['draft', 'signal_detected'],
        ],
        'queued' => [
            'label' => 'Queued',
            'color' => 'yellow',
            'statuses' => ['scoring', 'planning', 'awaiting_approval', 'approved'],
        ],
        'running' => [
            'label' => 'Running',
            'color' => 'blue',
            'statuses' => ['building', 'executing', 'awaiting_children', 'collecting_metrics', 'evaluating', 'iterating'],
        ],
        'completed' => [
            'label' => 'Completed',
            'color' => 'green',
            'statuses' => ['completed'],
        ],
        'failed' => [
            'label' => 'Failed',
            'color' => 'red',
            'statuses' => ['scoring_failed', 'planning_failed', 'building_failed', 'execution_failed', 'killed', 'discarded', 'expired', 'rejected'],
        ],
    ];

    public function mount(Project $project): void
    {
        $this->project = $project;
    }

    public function toggleView(): void
    {
        $this->viewMode = $this->viewMode === 'kanban' ? 'graph' : 'kanban';
    }

    private function projectExperimentIds(): array
    {
        return ProjectRun::where('project_id', $this->project->id)
            ->whereNotNull('experiment_id')
            ->pluck('experiment_id')
            ->all();
    }

    public function updateSortOrder(string $experimentId, int $newOrder): void
    {
        Experiment::whereIn('id', $this->projectExperimentIds())
            ->where('id', $experimentId)
            ->update(['sort_order' => $newOrder]);
    }

    public function getExperimentsByColumn(): array
    {
        $experiments = Experiment::whereIn('id', $this->projectExperimentIds())
            ->orderBy('sort_order')
            ->orderBy('created_at', 'desc')
            ->get();

        $columns = [];
        foreach (static::$columns as $key => $config) {
            $columns[$key] = $experiments->filter(
                function (Experiment $e) use ($config): bool {
                    /** @var ExperimentStatus $status */
                    $status = $e->status;

                    return in_array($status->value, $config['statuses']);
                },
            )->values();
        }

        return $columns;
    }

    public function getGraphData(): array
    {
        $experiments = Experiment::whereIn('id', $this->projectExperimentIds())
            ->orderBy('created_at')
            ->get();

        $nodes = [];
        $edges = [];

        foreach ($experiments as $exp) {
            /** @var ExperimentStatus $expStatus */
            $expStatus = $exp->status;
            $nodes[] = [
                'id' => $exp->id,
                'label' => $exp->title ?? 'Experiment',
                'status' => $expStatus->value,
                'iteration' => $exp->current_iteration,
            ];

            // Link experiments that share workflow_id (sequential runs)
            if ($exp->workflow_id) {
                $prev = $experiments->where('workflow_id', $exp->workflow_id)
                    ->where('created_at', '<', $exp->created_at)
                    ->last();

                if ($prev) {
                    $edges[] = ['from' => $prev->id, 'to' => $exp->id];
                }
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    public function render()
    {
        return view('livewire.projects.project-kanban-page', [
            'columns' => static::$columns,
            'experimentsByColumn' => $this->getExperimentsByColumn(),
            'graphData' => $this->viewMode === 'graph' ? $this->getGraphData() : [],
        ])->layout('layouts.app', ['header' => $this->project->title.' — Board']);
    }
}
