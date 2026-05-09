<?php

declare(strict_types=1);

namespace App\Domain\Release\Actions;

use App\Domain\Release\Enums\ReleaseStatus;
use App\Domain\Release\Models\Release;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PublishReleaseAction
{
    /**
     * Publishing is a one-way action — sets status to Published,
     * stamps published_at, and generates a stable share token.
     *
     * Idempotent on already-published releases (no-op, returns existing token).
     *
     * @throws InvalidArgumentException when the release is archived
     */
    public function execute(Release $release): Release
    {
        if ($release->isArchived()) {
            throw new InvalidArgumentException('Cannot publish an archived release.');
        }

        if ($release->isPublished()) {
            return $release;
        }

        $release->update([
            'status' => ReleaseStatus::Published,
            'share_token' => $release->share_token ?: Str::random(48),
            'published_at' => now(),
        ]);

        return $release->refresh();
    }
}
