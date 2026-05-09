<?php

declare(strict_types=1);

namespace App\Domain\Release\Actions;

use App\Domain\Release\Models\Release;
use App\Domain\Release\Models\ReleaseArtifact;
use App\Models\Artifact;
use InvalidArgumentException;

class AttachArtifactAction
{
    /**
     * Idempotent attach. Re-attaching an existing artifact updates its
     * snapshot version + sort order rather than failing.
     *
     * @throws InvalidArgumentException when the release is archived
     *                                  or the artifact belongs to a different team
     */
    public function execute(Release $release, Artifact $artifact, ?int $sortOrder = null): ReleaseArtifact
    {
        if ($release->isArchived()) {
            throw new InvalidArgumentException('Cannot attach artifacts to an archived release.');
        }

        if ($release->team_id !== $artifact->team_id) {
            throw new InvalidArgumentException('Artifact must belong to the same team as the release.');
        }

        $resolvedSort = $sortOrder ?? ($release->releaseArtifacts()->max('sort_order') ?? -1) + 1;

        return ReleaseArtifact::updateOrCreate(
            [
                'release_id' => $release->id,
                'artifact_id' => $artifact->id,
            ],
            [
                'artifact_version' => $artifact->current_version ?? 1,
                'sort_order' => $resolvedSort,
            ],
        );
    }
}
