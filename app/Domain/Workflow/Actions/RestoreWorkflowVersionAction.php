<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowVersion;
use InvalidArgumentException;

class RestoreWorkflowVersionAction
{
    /**
     * Re-apply the graph of an earlier snapshot as a normal graph update.
     * The current graph is snapshotted and the version moves forward, so
     * history is never rewritten.
     */
    public function execute(Workflow $workflow, string $versionId): Workflow
    {
        /** @var WorkflowVersion|null $version */
        $version = WorkflowVersion::withoutGlobalScopes()
            ->where('workflow_id', $workflow->id)
            ->whereKey($versionId)
            ->first();

        if ($version === null) {
            throw new InvalidArgumentException('Version not found for this workflow.');
        }

        $updated = app(UpdateWorkflowAction::class)->execute(
            workflow: $workflow,
            nodes: $version->graph['nodes'] ?? [],
            edges: $version->graph['edges'] ?? [],
        );

        activity()
            ->performedOn($updated)
            ->withProperties([
                'restored_version' => $version->version,
                'new_version' => $updated->version,
            ])
            ->log('workflow.version_restored');

        return $updated->refresh();
    }
}
