<?php

namespace App\Domain\Signal\Actions;

use App\Domain\Signal\Jobs\ExtractSignalEntitiesJob;
use App\Domain\Signal\Jobs\ProcessSignalMediaJob;
use App\Domain\Signal\Models\Signal;
use App\Models\Blacklist;
use Illuminate\Http\UploadedFile;

class IngestSignalAction
{
    /**
     * @param  array<int, UploadedFile|mixed>  $files  Optional file attachments for media processing
     */
    public function execute(
        string $sourceType,
        string $sourceIdentifier,
        array $payload,
        array $tags = [],
        ?string $experimentId = null,
        array $files = [],
    ): ?Signal {
        $contentHash = hash('sha256', json_encode($payload));

        // Dedup: check if signal with same content_hash already exists
        $existing = Signal::where('content_hash', $contentHash)->first();
        if ($existing) {
            return $this->mergeIntoExisting($existing, $tags, $payload, $sourceIdentifier);
        }

        // Check blacklist
        if ($this->isBlacklisted($payload)) {
            return null;
        }

        $signal = Signal::create([
            'experiment_id' => $experimentId,
            'source_type' => $sourceType,
            'source_identifier' => $sourceIdentifier,
            'payload' => $payload,
            'content_hash' => $contentHash,
            'tags' => $tags,
            'received_at' => now(),
        ]);

        // Handle file attachments
        foreach ($files as $file) {
            if ($file instanceof UploadedFile && $file->isValid()) {
                $signal->addMedia($file)->toMediaCollection('attachments');
            }
        }

        // Dispatch media analysis if files were attached
        if (! empty($files)) {
            ProcessSignalMediaJob::dispatch($signal->id);
        }

        // Dispatch entity extraction for new signals
        ExtractSignalEntitiesJob::dispatch($signal->id);

        return $signal;
    }

    /**
     * Merge a duplicate signal into the existing one instead of discarding.
     */
    private function mergeIntoExisting(Signal $existing, array $tags, array $payload, string $sourceIdentifier): Signal
    {
        $mergedTags = array_values(array_unique(
            array_merge($existing->tags ?? [], $tags)
        ));

        $mergedPayload = array_merge($existing->payload ?? [], $payload);

        $updates = [
            'tags' => $mergedTags,
            'payload' => $mergedPayload,
            'duplicate_count' => ($existing->duplicate_count ?? 0) + 1,
            'last_received_at' => now(),
        ];

        // Track additional sources
        if ($sourceIdentifier !== $existing->source_identifier) {
            $sources = $mergedPayload['_additional_sources'] ?? [];
            if (! in_array($sourceIdentifier, $sources, true)) {
                $sources[] = $sourceIdentifier;
                $updates['payload'] = array_merge($mergedPayload, ['_additional_sources' => $sources]);
            }
        }

        $existing->update($updates);

        return $existing;
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
