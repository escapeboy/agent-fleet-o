<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowGitSync;

/**
 * Link (or update) a Workflow ↔ GitRepository sync. Build #5, Trendshift top-5 sprint.
 *
 * Idempotent — calling with the same workflow updates the existing link.
 */
class CreateWorkflowGitSyncAction
{
    public function execute(
        string $workflowId,
        string $gitRepositoryId,
        string $teamId,
        string $branch = 'fleetq-sync',
        string $pathPrefix = 'workflows/',
    ): WorkflowGitSync {
        $workflow = Workflow::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->findOrFail($workflowId);

        $repo = GitRepository::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->findOrFail($gitRepositoryId);

        return WorkflowGitSync::updateOrCreate(
            ['workflow_id' => $workflow->id],
            [
                'git_repository_id' => $repo->id,
                'team_id' => $teamId,
                'branch' => $branch,
                'path_prefix' => rtrim($pathPrefix, '/').'/',
            ],
        );
    }
}
