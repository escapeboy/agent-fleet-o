<?php

namespace App\Domain\ErrorMode\Actions;

use App\Domain\ErrorMode\Models\ErrorMode;
use Illuminate\Support\Str;

/**
 * Find-or-create an error mode for a team by its normalized label, and record
 * one occurrence. Deterministic slug-based clustering (embedding clustering is
 * future work). Race-safe: relies on the (team_id, slug) unique constraint and
 * an atomic increment.
 */
final class RecordErrorModeOccurrenceAction
{
    private const MAX_TRACE_IDS = 50;

    /**
     * @param  array<string,mixed>  $metadata
     */
    public function execute(
        string $teamId,
        string $label,
        ?string $traceId = null,
        array $metadata = [],
    ): ErrorMode {
        $slug = Str::slug($label) ?: 'unnamed';
        $name = trim($label) !== '' ? trim($label) : 'Unnamed error mode';

        $mode = ErrorMode::query()->firstOrCreate(
            ['team_id' => $teamId, 'slug' => $slug],
            [
                'name' => mb_substr($name, 0, 255),
                'lever' => 'unassigned',
                'status' => 'open',
                'occurrence_count' => 0,
                'first_seen_at' => now(),
                'example_trace_ids' => [],
                'metadata' => $metadata,
            ],
        );

        $mode->increment('occurrence_count');
        $mode->last_seen_at = now();

        if ($traceId !== null && $traceId !== '') {
            $traces = $mode->example_trace_ids ?? [];
            if (! in_array($traceId, $traces, true)) {
                $traces[] = $traceId;
                $mode->example_trace_ids = array_slice($traces, -self::MAX_TRACE_IDS);
            }
        }

        $mode->save();

        return $mode;
    }
}
