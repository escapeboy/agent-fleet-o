<?php

declare(strict_types=1);

namespace App\Domain\Release\Crypto\Services;

use App\Domain\Release\Models\Release;

/**
 * Produces a deterministic, canonical JSON representation of a release for signing.
 *
 * Determinism guarantees:
 *  - Top-level keys sorted alphabetically
 *  - Artifacts sorted by id
 *  - No volatile fields (no timestamps with sub-second precision, no tokens)
 *  - No keys not part of the signed payload
 *
 * Two calls with the same input produce byte-identical output.
 */
class CanonicalizeManifest
{
    public function canonicalize(Release $release): string
    {
        $artifacts = $release->artifacts()->orderBy('artifacts.id')->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->type,
                'version' => (int) $a->pivot->artifact_version,
            ])
            ->all();

        $payload = [
            'kind' => 'release',
            'name' => $release->name,
            'notes' => $release->notes ?? '',
            'release_id' => $release->id,
            'slug' => $release->slug,
            'team_id' => $release->team_id,
            'version' => $release->version,
            // published_at is stamped at sign-time; expressed as ISO date only
            'published_date' => $release->published_at?->toDateString(),
            'artifacts' => $artifacts,
        ];

        // Sort top-level keys for determinism (PHP arrays preserve insertion order;
        // we rely on JSON_THROW + JSON_UNESCAPED_SLASHES + ksort to make the output stable).
        ksort($payload);

        return json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
