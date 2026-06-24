<?php

namespace App\Domain\Knowledge\Services;

/**
 * Source-ACL retrieval gate. Decides whether a chunk is visible to a set of
 * user groups, and builds the Postgres WHERE fragment for vector retrieval.
 *
 * null ACL (or no allowed_group_slugs) = unrestricted. When the feature is off,
 * the SQL clause is empty so retrieval is unchanged.
 */
class SourceAclGate
{
    public function enabled(): bool
    {
        return (bool) config('source_acl.enabled', false);
    }

    /**
     * In-memory predicate (used in tests / non-SQL paths).
     *
     * @param  array<string, mixed>|null  $acl
     * @param  list<string>  $userGroups
     */
    public function allows(?array $acl, array $userGroups): bool
    {
        if ($acl === null) {
            return true;
        }

        $allowed = $acl['allowed_group_slugs'] ?? null;

        if (empty($allowed)) {
            return true;
        }

        return count(array_intersect($allowed, $userGroups)) > 0;
    }

    /**
     * Postgres WHERE fragment + bindings for the vector query. Empty when the
     * feature is disabled. Uses jsonb_exists_any() (not the `?|` operator, which
     * collides with PDO `?` placeholders).
     *
     * @param  list<string>  $userGroups
     * @return array{sql: string, bindings: list<string>}
     */
    public function sqlClause(array $userGroups): array
    {
        if (! $this->enabled()) {
            return ['sql' => '', 'bindings' => []];
        }

        // No groups → only unrestricted chunks are visible.
        if ($userGroups === []) {
            return ['sql' => ' AND source_acl IS NULL', 'bindings' => []];
        }

        $placeholders = implode(',', array_fill(0, count($userGroups), '?'));

        return [
            'sql' => " AND (source_acl IS NULL OR jsonb_exists_any(source_acl->'allowed_group_slugs', array[{$placeholders}]))",
            'bindings' => $userGroups,
        ];
    }
}
