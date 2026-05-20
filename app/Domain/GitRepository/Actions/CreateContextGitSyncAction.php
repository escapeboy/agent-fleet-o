<?php

namespace App\Domain\GitRepository\Actions;

use App\Domain\GitRepository\Models\ContextGitSync;
use App\Domain\GitRepository\Models\GitRepository;

/**
 * Link (or update) a team's context → GitRepository sync. Kanwas-inspired sprint.
 *
 * Idempotent — one sync per team, keyed on team_id.
 */
class CreateContextGitSyncAction
{
    public function execute(
        string $teamId,
        string $gitRepositoryId,
        string $branch = 'fleetq-context',
        bool $syncArtifacts = true,
        bool $syncMemory = true,
        string $artifactPathPrefix = 'artifacts/',
        string $memoryPathPrefix = 'memory/',
    ): ContextGitSync {
        $repo = GitRepository::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->findOrFail($gitRepositoryId);

        return ContextGitSync::updateOrCreate(
            ['team_id' => $teamId],
            [
                'git_repository_id' => $repo->id,
                'branch' => trim($branch) !== '' ? trim($branch) : 'fleetq-context',
                'sync_artifacts' => $syncArtifacts,
                'sync_memory' => $syncMemory,
                'artifact_path_prefix' => rtrim($artifactPathPrefix, '/').'/',
                'memory_path_prefix' => rtrim($memoryPathPrefix, '/').'/',
            ],
        );
    }
}
