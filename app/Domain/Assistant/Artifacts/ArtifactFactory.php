<?php

namespace App\Domain\Assistant\Artifacts;

use Illuminate\Support\Facades\Log;

/**
 * Single dispatch point for turning raw LLM artifact JSON into typed VOs.
 *
 * Enforces:
 *  - Whitelist of allowed types (unknown types → silently dropped + logged).
 *  - Per-artifact payload size cap (anything > BaseArtifact::MAX_PAYLOAD_BYTES dropped).
 *  - Global caps (max 3 artifacts per message, max 32KB total payload).
 *
 * Returns only fully-validated VOs. Anything that fails sanitization is
 * logged at WARNING level and never reaches the database or renderer.
 */
final class ArtifactFactory
{
    /** @var array<string, class-string<BaseArtifact>> */
    private const TYPE_MAP = [
        DataTableArtifact::TYPE => DataTableArtifact::class,
        ChartArtifact::TYPE => ChartArtifact::class,
        ChoiceCardsArtifact::TYPE => ChoiceCardsArtifact::class,
        FormArtifact::TYPE => FormArtifact::class,
        LinkListArtifact::TYPE => LinkListArtifact::class,
        CodeDiffArtifact::TYPE => CodeDiffArtifact::class,
        ConfirmationDialogArtifact::TYPE => ConfirmationDialogArtifact::class,
        MetricCardArtifact::TYPE => MetricCardArtifact::class,
        ProgressTrackerArtifact::TYPE => ProgressTrackerArtifact::class,
    ];

    public const MAX_ARTIFACTS_PER_MESSAGE = 3;

    public const MAX_TOTAL_PAYLOAD_BYTES = 32_000;

    /**
     * Build a single VO from raw LLM data. Returns null on any failure.
     *
     * @param  array<string, mixed>  $raw
     * @param  list<array<string, mixed>>  $toolCallsInTurn
     */
    public static function build(array $raw, array $toolCallsInTurn): ?BaseArtifact
    {
        $type = is_string($raw['type'] ?? null) ? $raw['type'] : null;
        if ($type === null || ! isset(self::TYPE_MAP[$type])) {
            Log::info('ArtifactFactory: unknown type rejected', ['type' => $type]);

            return null;
        }

        $class = self::TYPE_MAP[$type];

        try {
            $artifact = $class::fromLlmArray($raw, $toolCallsInTurn);
        } catch (\Throwable $e) {
            Log::warning('ArtifactFactory: factory threw', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($artifact === null) {
            return null;
        }

        if ($artifact->sizeBytes() > BaseArtifact::MAX_PAYLOAD_BYTES) {
            Log::warning('ArtifactFactory: artifact exceeds per-artifact payload cap', [
                'type' => $type,
                'size_bytes' => $artifact->sizeBytes(),
            ]);

            return null;
        }

        return $artifact;
    }

    /**
     * Build many from a list, enforcing global caps.
     *
     * @param  list<array<string, mixed>>  $rawArtifacts
     * @param  list<array<string, mixed>>  $toolCallsInTurn
     * @return list<BaseArtifact>
     */
    public static function buildMany(array $rawArtifacts, array $toolCallsInTurn): array
    {
        $built = [];
        $totalBytes = 0;

        foreach (array_slice($rawArtifacts, 0, self::MAX_ARTIFACTS_PER_MESSAGE) as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $artifact = self::build($raw, $toolCallsInTurn);
            if ($artifact === null) {
                continue;
            }

            $size = $artifact->sizeBytes();
            if ($totalBytes + $size > self::MAX_TOTAL_PAYLOAD_BYTES) {
                Log::warning('ArtifactFactory: global payload cap reached, remaining artifacts dropped', [
                    'dropped_count' => count($rawArtifacts) - count($built),
                ]);
                break;
            }

            $built[] = $artifact;
            $totalBytes += $size;
        }

        return $built;
    }
}
