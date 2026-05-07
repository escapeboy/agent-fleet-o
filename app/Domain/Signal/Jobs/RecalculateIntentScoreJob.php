<?php

namespace App\Domain\Signal\Jobs;

use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Models\CompanyIntentScore;
use App\Domain\Signal\Services\SignalStackingEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Recalculates the composite intent score for a company or person.
 *
 * Dispatched by IngestSignalAction whenever a new signal arrives for
 * an entity with a known source_identifier (e.g. LinkedIn URL, domain).
 *
 * Debounced: skipped if recalculate_after is in the future (set to
 * now + 15 minutes after each calculation to prevent rapid re-runs).
 *
 * When the score crosses the 50-point threshold (warm intent), a
 * synthetic 'intent_score' signal is ingested so TriggerRules can
 * fire on composite intent without knowing individual signal details.
 */
class RecalculateIntentScoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly string $teamId,
        public readonly string $entityKey,
        public readonly string $entityType,
    ) {
        $this->onQueue('metrics');
    }

    public function handle(SignalStackingEngine $engine, IngestSignalAction $ingestAction): void
    {
        // Debounce: skip if a recalculation was triggered recently
        $existing = CompanyIntentScore::where('team_id', $this->teamId)
            ->where('entity_key', $this->entityKey)
            ->where('entity_type', $this->entityType)
            ->first();

        if ($existing && $existing->recalculate_after?->isFuture()) {
            return;
        }

        $previousScore = $existing->composite_score ?? 0;

        $score = $engine->recalculate($this->teamId, $this->entityKey, $this->entityType);

        // Emit a synthetic intent_score signal when the threshold is newly crossed.
        // This lets TriggerRules fire on composite intent without needing to know
        // the details of individual signals.
        if ($score->composite_score >= 50 && $previousScore < 50) {
            $ingestAction->execute(
                sourceType: 'intent_score',
                sourceIdentifier: $this->entityKey,
                payload: array_merge($score->score_breakdown, [
                    'entity_key' => $this->entityKey,
                    'entity_type' => $this->entityType,
                    'composite_score' => $score->composite_score,
                    'intent_tag' => $score->intentTag(),
                ]),
                tags: ['intent', $score->intentTag()],
                teamId: $this->teamId,
            );
        }
    }
}
