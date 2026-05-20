<?php

declare(strict_types=1);

namespace App\Domain\Release\Actions;

use App\Domain\Release\Enums\ReleaseStatus;
use App\Domain\Release\Models\Release;

class ArchiveReleaseAction
{
    public function execute(Release $release): Release
    {
        if ($release->isArchived()) {
            return $release;
        }

        $release->update([
            'status' => ReleaseStatus::Archived,
            'archived_at' => now(),
        ]);

        return $release->refresh();
    }
}
