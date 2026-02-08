<?php

namespace App\Domain\Signal\Actions;

use App\Domain\Signal\Models\Signal;
use App\Models\Blacklist;

class IngestSignalAction
{
    public function execute(
        string $sourceType,
        string $sourceIdentifier,
        array $payload,
        array $tags = [],
        ?string $experimentId = null,
    ): ?Signal {
        $contentHash = hash('sha256', json_encode($payload));

        // Dedup: check if signal with same content_hash already exists
        $existing = Signal::where('content_hash', $contentHash)->first();
        if ($existing) {
            return null;
        }

        // Check blacklist
        if ($this->isBlacklisted($payload)) {
            return null;
        }

        return Signal::create([
            'experiment_id' => $experimentId,
            'source_type' => $sourceType,
            'source_identifier' => $sourceIdentifier,
            'payload' => $payload,
            'content_hash' => $contentHash,
            'tags' => $tags,
            'received_at' => now(),
        ]);
    }

    private function isBlacklisted(array $payload): bool
    {
        $checkValues = array_merge(
            array_values(array_filter($payload, 'is_string')),
            [$payload['email'] ?? '', $payload['domain'] ?? '', $payload['company'] ?? ''],
        );

        $checkValues = array_filter($checkValues);

        if (empty($checkValues)) {
            return false;
        }

        return Blacklist::whereIn('value', $checkValues)->exists();
    }
}
