<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Support\Facades\DB;

class DeleteWorkflowAction
{
    /**
     * Archive or delete a workflow.
     *
     * If the workflow is referenced by experiments, it is archived instead of deleted.
     */
    public function execute(Workflow $workflow, bool $force = false): void
    {
        DB::transaction(function () use ($workflow, $force) {
            $hasExperiments = $workflow->experiments()->exists();

            if ($hasExperiments && ! $force) {
                // Archive instead of delete — experiments still reference this workflow
                $workflow->update(['status' => WorkflowStatus::Archived]);

                activity()
                    ->performedOn($workflow)
                    ->withProperties(['reason' => 'has_experiments'])
                    ->log('workflow.archived');

                return;
            }

            $name = $workflow->name;
            $id = $workflow->id;

            // Cascade delete: edges and nodes are deleted via FK cascadeOnDelete
            $workflow->delete();

            activity()
                ->withProperties(['workflow_id' => $id, 'name' => $name])
                ->log('workflow.deleted');
        });
    }
}
