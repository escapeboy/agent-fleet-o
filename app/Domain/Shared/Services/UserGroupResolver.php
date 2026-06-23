<?php

namespace App\Domain\Shared\Services;

use App\Models\User;

/**
 * Resolves the group slugs a user belongs to, for source-ACL-aware retrieval.
 *
 * First cut: groups are derived from team membership + role (`team:{id}` and
 * `team:{id}:role:{role}`). This is the single seam where a real IdP/SSO group
 * source (Onyx-style group sync) would be merged in later.
 */
class UserGroupResolver
{
    /**
     * @return list<string>
     */
    public function groupsFor(?User $user, ?string $teamId): array
    {
        if ($user === null || $teamId === null) {
            return [];
        }

        $groups = ["team:{$teamId}"];

        $membership = $user->teams()->where('teams.id', $teamId)->first();
        $role = $membership?->pivot?->getAttribute('role');

        if ($role) {
            $groups[] = "team:{$teamId}:role:{$role}";
        }

        return $groups;
    }
}
