<?php

declare(strict_types=1);

namespace App\Domain\Release\Actions;

use App\Domain\Release\Crypto\Actions\SignReleaseAction;
use App\Domain\Release\Enums\ReleaseStatus;
use App\Domain\Release\Models\Release;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PublishReleaseAction
{
    public function __construct(
        private readonly SignReleaseAction $signer,
    ) {}

    /**
     * Publishing is a one-way action — sets status to Published, stamps
     * published_at, generates a stable share token, and (if a signing key
     * exists) signs the release manifest with the team's active Ed25519 key.
     *
     * Idempotent on already-published releases (no-op, returns existing token).
     *
     * Signing is best-effort — if no key exists yet, the release is published
     * unsigned and the share-page badge will say so. This avoids forcing every
     * team to set up signing before they can publish.
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
        $release->refresh();

        // Best-effort sign — release stays published even if no key is configured.
        try {
            $this->signer->execute($release);
            $release->refresh();
        } catch (InvalidArgumentException) {
            // No active key — leave release unsigned. UI will surface this state.
        }

        return $release;
    }
}
