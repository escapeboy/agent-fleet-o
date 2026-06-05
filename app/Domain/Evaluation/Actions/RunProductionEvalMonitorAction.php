<?php

namespace App\Domain\Evaluation\Actions;

use App\Domain\Evaluation\Models\EvaluationDataset;
use App\Domain\Evaluation\Models\EvaluationMonitorSnapshot;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Services\ProviderResolver;

/**
 * Agentic AI Flywheel #5 — run the team's eval set against sampled production
 * traffic as a continuous monitor and record a score snapshot. Reuses the
 * existing replay grading path (the "second runner"); decay in the snapshot
 * series is a drift signal that arrives before any user complaint.
 */
final class RunProductionEvalMonitorAction
{
    public function __construct(
        private readonly ReplayEvaluationDatasetAction $replay,
        private readonly ProviderResolver $providerResolver,
    ) {}

    public function execute(Team $team): ?EvaluationMonitorSnapshot
    {
        if (! config('evaluation.production_monitor.enabled', false)) {
            return null;
        }

        $datasetName = (string) config('evaluation.auto_eval.dataset_name', 'Production Regressions');
        $dataset = EvaluationDataset::query()
            ->where('team_id', $team->id)
            ->where('name', $datasetName)
            ->first();

        if ($dataset === null || $dataset->cases()->count() === 0) {
            return null;
        }

        $sampleSize = max(1, (int) config('evaluation.production_monitor.sample_size', 20));
        $resolved = $this->providerResolver->resolve(team: $team, purpose: 'run');

        $run = $this->replay->execute(
            teamId: $team->id,
            datasetId: $dataset->id,
            targetProvider: $resolved['provider'],
            targetModel: $resolved['model'],
            maxCases: $sampleSize,
        );

        $summary = $run->summary;

        return EvaluationMonitorSnapshot::create([
            'team_id' => $team->id,
            'dataset_id' => $dataset->id,
            'run_id' => $run->id,
            'avg_score' => data_get($summary, 'overall_avg_score'),
            'pass_rate' => data_get($summary, 'pass_rate_pct'),
            'active_count' => (int) data_get($summary, 'passed', 0) + (int) data_get($summary, 'failed', 0),
            'deferred_passed' => (int) data_get($summary, 'deferred_passed', 0),
            'sampled_count' => (int) data_get($summary, 'total_cases', 0),
        ]);
    }
}
