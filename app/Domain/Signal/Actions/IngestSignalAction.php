<?php

namespace App\Domain\Signal\Actions;

use App\Domain\Signal\Jobs\ExtractSignalEntitiesJob;
use App\Domain\Signal\Jobs\ProcessSignalMediaJob;
use App\Domain\Signal\Models\Signal;
use App\Domain\Trigger\Jobs\EvaluateTriggerRulesJob;
use App\Models\Blacklist;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class IngestSignalAction
{
    /**
     * @param  array<int, UploadedFile|mixed>  $files  Optional file attachments for media processing
     * @param  string|null  $sourceNativeId  Stable provider-assigned ID (e.g. "ISSUE-123" from Sentry).
     *                                       When provided, dedup by (source_type + source_native_id) takes
     *                                       priority over content_hash, preventing repeated webhooks for the
     *                                       same upstream event from creating duplicate signals.
     */
    public function execute(
        string $sourceType,
        string $sourceIdentifier,
        array $payload,
        array $tags = [],
        ?string $experimentId = null,
        array $files = [],
        ?string $sourceNativeId = null,
    ): ?Signal {
        // Alert storm protection: limit signals per source_type to prevent runaway alert floods.
        // Default: 60 signals/minute per source_type. Configurable via config('signals.rate_limit').
        $rateKey = 'signal_ingest:'.$sourceType;
        $maxPerMinute = (int) config('signals.storm_rate_limit', 60);

        if (! RateLimiter::attempt($rateKey, $maxPerMinute, fn () => null)) {
            Log::warning('IngestSignalAction: alert storm rate limit exceeded', [
                'source_type' => $sourceType,
                'source_identifier' => $sourceIdentifier,
                'limit' => $maxPerMinute,
            ]);

            return null;
        }

        // Dedup by stable provider ID first (Sentry issue ID, Datadog alert ID, PagerDuty incident ID)
        if ($sourceNativeId) {
            $existing = Signal::where('source_type', $sourceType)
                ->where('source_native_id', $sourceNativeId)
                ->first();
            if ($existing) {
                return $this->mergeIntoExisting($existing, $tags, $payload, $sourceIdentifier);
            }
        }

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
            'source_native_id' => $sourceNativeId,
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

        // Evaluate trigger rules asynchronously (zero overhead to HTTP response)
        EvaluateTriggerRulesJob::dispatch($signal->id);

        return $signal;
    }

    /**
     * Merge a duplicate signal into the existing one instead of discarding.
     */
    private function mergeIntoExisting(Signal $existing, array $tags, array $payload, string $sourceIdentifier): Signal
    {
        $mergedTags = array_values(array_unique(
            array_merge($existing->tags ?? [], $tags),
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
