<?php

namespace App\Domain\Evaluation\Actions;

use App\Domain\ErrorMode\Actions\RecordErrorModeOccurrenceAction;
use App\Domain\Evaluation\Enums\EvaluationCaseSource;
use App\Domain\Evaluation\Enums\EvaluationCaseStatus;
use App\Domain\Evaluation\Models\EvaluationCase;
use App\Domain\Evaluation\Models\EvaluationDataset;

/**
 * Agentic AI Flywheel #1 — append a *deferred* regression case the moment a
 * failure mode is named, decoupled from when (or whether) the fix ships.
 *
 * The eval set grows at the cadence of triage, not the cadence of fixes. New
 * cases land in a per-team "Production Regressions" dataset as deferred (so they
 * do not block the gate until promoted) and optionally link to the error-mode
 * catalog.
 */
final class AppendRegressionCaseAction
{
    public function __construct(
        private readonly RecordErrorModeOccurrenceAction $recordErrorMode,
    ) {}

    /**
     * @param  array<string,mixed>  $metadata  Provenance (source ids, trace_id, …)
     * @param  bool  $force  Bypass the auto_eval flag (manual append via MCP/UI)
     * @return EvaluationCase|null null when disabled, input empty, or a duplicate open case already exists
     */
    public function execute(
        string $teamId,
        string $input,
        ?string $failingOutput,
        string $errorModeLabel,
        EvaluationCaseSource $source,
        array $metadata = [],
        ?string $expectedOutput = null,
        bool $force = false,
    ): ?EvaluationCase {
        if (! $force && ! config('evaluation.auto_eval.enabled', false)) {
            return null;
        }

        $input = trim($input);
        if ($input === '') {
            return null;
        }

        $datasetName = (string) config('evaluation.auto_eval.dataset_name', 'Production Regressions');

        $dataset = EvaluationDataset::query()->firstOrCreate(
            ['team_id' => $teamId, 'name' => $datasetName],
            [
                'description' => 'Auto-collected production regressions (Agentic AI Flywheel).',
                'case_count' => 0,
            ],
        );

        // Idempotency: at most one open deferred case per (team, error_mode, input).
        $duplicate = EvaluationCase::query()
            ->where('team_id', $teamId)
            ->where('dataset_id', $dataset->id)
            ->where('status', EvaluationCaseStatus::Deferred->value)
            ->where('error_mode', $errorModeLabel)
            ->where('input', $input)
            ->exists();
        if ($duplicate) {
            return null;
        }

        $errorModeId = null;
        if (config('evaluation.error_mode_catalog.enabled', false)) {
            $mode = $this->recordErrorMode->execute(
                teamId: $teamId,
                label: $errorModeLabel,
                traceId: isset($metadata['trace_id']) ? (string) $metadata['trace_id'] : null,
                metadata: ['source' => $source->value],
            );
            $errorModeId = $mode->id;
        }

        $case = EvaluationCase::create([
            'dataset_id' => $dataset->id,
            'team_id' => $teamId,
            'input' => $input,
            'expected_output' => $expectedOutput,
            'status' => EvaluationCaseStatus::Deferred,
            'source' => $source->value,
            'error_mode' => $errorModeLabel,
            'error_mode_id' => $errorModeId,
            'context' => null,
            'metadata' => array_merge($metadata, [
                'detected_at' => now()->toIso8601String(),
                'failing_output_excerpt' => $failingOutput !== null
                    ? mb_strimwidth($failingOutput, 0, 1000, '…')
                    : null,
            ]),
        ]);

        $dataset->increment('case_count');

        return $case;
    }
}
