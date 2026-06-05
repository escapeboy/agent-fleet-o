<?php

namespace App\Console\Commands;

use App\Domain\Evaluation\Actions\RunProductionEvalMonitorAction;
use App\Domain\Evaluation\Models\EvaluationDataset;
use App\Domain\Shared\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Agentic AI Flywheel #5 — continuous production eval monitor.
 */
class RunProductionEvalMonitorCommand extends Command
{
    protected $signature = 'evaluation:monitor-production';

    protected $description = 'Run the eval set against sampled production traffic as a continuous monitor (Agentic AI Flywheel #5).';

    public function handle(RunProductionEvalMonitorAction $action): int
    {
        if (! config('evaluation.production_monitor.enabled', false)) {
            $this->info('Production eval monitor disabled — skipping.');

            return self::SUCCESS;
        }

        $datasetName = (string) config('evaluation.auto_eval.dataset_name', 'Production Regressions');
        $teamIds = EvaluationDataset::query()
            ->where('name', $datasetName)
            ->where('case_count', '>', 0)
            ->pluck('team_id')
            ->unique();

        $snapshots = 0;
        $skipped = 0;

        foreach ($teamIds as $teamId) {
            $team = Team::find($teamId);
            if ($team === null) {
                $skipped++;

                continue;
            }

            try {
                $snapshot = $action->execute($team);
                $snapshot !== null ? $snapshots++ : $skipped++;
            } catch (\Throwable $e) {
                $skipped++;
                Log::warning('evaluation:monitor-production failed for team', ['team_id' => $teamId, 'error' => $e->getMessage()]);
                $this->error("team {$teamId}: ".$e->getMessage());
            }
        }

        $this->info("Production eval monitor: {$snapshots} snapshot(s) written, {$skipped} skipped.");

        return self::SUCCESS;
    }
}
