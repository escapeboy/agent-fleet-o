<?php

namespace App\Domain\GitRepository\Services;

use App\Domain\GitRepository\DTOs\WarmBuildChangeset;
use App\Domain\GitRepository\DTOs\WritableRootsGrant;

/**
 * Validates a warm-build candidate's changeset against its writable-roots grant
 * (Shepherd borrow #3). Pure over the changeset's file list — no git, no writes —
 * so it is deterministic and trivially testable. The git capture lives in
 * WarmBuildChangeset::capture().
 */
class ChangesetPolicyValidator
{
    /**
     * Repo-relative changed paths that the grant does NOT permit.
     *
     * @return list<string>
     */
    public function violations(WarmBuildChangeset $changeset, WritableRootsGrant $grant): array
    {
        return array_values(array_filter(
            $changeset->files,
            static fn (string $path): bool => ! $grant->permits($path),
        ));
    }

    public function isWithinRoots(WarmBuildChangeset $changeset, WritableRootsGrant $grant): bool
    {
        return $this->violations($changeset, $grant) === [];
    }
}
