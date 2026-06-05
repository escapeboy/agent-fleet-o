<?php

namespace App\Console\Commands;

use App\Domain\Evaluation\Actions\EvaluateDriftSignalsAction;
use App\Domain\Evaluation\Models\EvaluationMonitorSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Agentic AI Flywheel #4 — compute drift signals for teams with recent activity.
 */
class CheckDriftSignalsCommand extends Command
{
    protected $signature = 'evaluation:check-drift';

    protected $description = 'Compute the four drift signals (input shift, eval decay, thumbs-down, latency/cost) per team (Agentic AI Flywheel #4).';

    public function handle(EvaluateDriftSignalsAction $action): int
    {
        if (! config('evaluation.drift_monitor.enabled', false)) {
            $this->info('Drift monitor disabled — skipping.');

            return self::SUCCESS;
        }

        $baselineHours = max(2, (int) config('evaluation.drift_monitor.baseline_hours', 168));
        $since = now()->subHours($baselineHours);

        $teamIds = collect()
            ->merge(EvaluationMonitorSnapshot::query()->where('created_at', '>=', $since)->distinct()->pluck('team_id'))
            ->merge(DB::table('chatbot_messages')->where('created_at', '>=', $since)->distinct()->pluck('team_id'))
            ->merge(DB::table('llm_request_logs')->where('created_at', '>=', $since)->distinct()->pluck('team_id'))
            ->filter()
            ->unique()
            ->values();

        $teamsChecked = 0;
        $breaches = 0;

        foreach ($teamIds as $teamId) {
            try {
                $signals = $action->execute((string) $teamId);
                $teamsChecked++;
                $breaches += count(array_filter($signals, fn ($s) => $s->breached));
            } catch (\Throwable $e) {
                Log::warning('evaluation:check-drift failed for team', ['team_id' => $teamId, 'error' => $e->getMessage()]);
                $this->error("team {$teamId}: ".$e->getMessage());
            }
        }

        $this->info("Drift check: {$teamsChecked} team(s) evaluated, {$breaches} breach(es).");

        return self::SUCCESS;
    }
}
