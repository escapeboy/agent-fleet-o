<?php

namespace App\Domain\GitRepository\Actions;

use App\Domain\GitRepository\Models\ContextGitSync;

/**
 * Remove a team's context git sync link. Kanwas-inspired sprint.
 */
class DeleteContextGitSyncAction
{
    public function execute(string $teamId): bool
    {
        $sync = ContextGitSync::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->first();

        if (! $sync) {
            return false;
        }

        $sync->delete();

        return true;
    }
}
