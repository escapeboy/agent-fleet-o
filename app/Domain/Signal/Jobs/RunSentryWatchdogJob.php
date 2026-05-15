<?php

namespace App\Domain\Signal\Jobs;

use App\Domain\Integration\Models\Integration;
use App\Domain\Signal\Actions\SendSentryWatchdogDigestAction;
use App\Domain\Signal\Actions\TriageSentryIssueAction;
use App\Domain\Signal\Enums\SentryTriageOutcome;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Models\SentryWatchdogRun;
use App\Domain\Signal\Models\Signal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Batch processor for one Sentry project: triages every Sentry-sourced Signal
 * ingested since the previous run, then sends the digest. Dispatched 2x/day by
 * the sentry:watchdog command.
 */
class RunSentryWatchdogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly string $integrationId)
    {
        $this->onQueue('default');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->integrationId))->expireAfter(900)->dontRelease()];
    }

    public function handle(TriageSentryIssueAction $triage, SendSentryWatchdogDigestAction $digest): void
    {
        $integration = Integration::withoutGlobalScopes()->find($this->integrationId);
        if ($integration === null) {
            return;
        }

        $run = SentryWatchdogRun::create([
            'integration_id' => $integration->id,
            'team_id' => $integration->team_id,
            'started_at' => now(),
        ]);

        // Status/flag based, not a time window: a Sentry signal is pending
        // watchdog triage when it has no delegated experiment, is not terminal,
        // and carries no prior watchdog stamp. A failed or overlapping run
        // therefore never drops signals — they stay pending for the next run.
        $signals = Signal::withoutGlobalScopes()
            ->where('team_id', $integration->team_id)
            ->where('source_type', 'sentry')
            ->whereNull('experiment_id')
            ->whereNotIn('status', [SignalStatus::Resolved->value, SignalStatus::Dismissed->value])
            ->whereNull('payload->sentry_watchdog_triaged_at')
            ->orderBy('created_at')
            ->get();

        // Group by Sentry issue id so a re-ingested issue is triaged once.
        $groups = $signals->groupBy(fn (Signal $signal) => $signal->payload['id'] ?? $signal->id);

        $triaged = 0;
        $prsOpened = 0;
        $investigateOnly = 0;
        $criticalCount = 0;
        $lines = [];

        foreach ($groups as $group) {
            /** @var Signal $signal */
            $signal = $group->first();

            try {
                $result = $triage->execute($signal);
            } catch (\Throwable $e) {
                // TriageSentryIssueAction is designed not to throw; this is
                // defence-in-depth so one bad signal cannot abort the batch.
                Log::warning('Sentry watchdog triage threw', [
                    'signal_id' => $signal->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($result->isCritical) {
                $criticalCount++;
            }

            if ($result->outcome === SentryTriageOutcome::Delegated) {
                $prsOpened++;
                $triaged++;
            } elseif ($result->outcome === SentryTriageOutcome::InvestigateOnly) {
                $investigateOnly++;
                $triaged++;
            } else {
                continue;
            }

            $lines[] = '• ['.strtoupper($result->tier->value).'] '
                .($result->summary ?? 'Sentry issue')
                .' — '.($result->wasDelegated() ? 'PR opened' : 'investigate-only');
        }

        $run->update([
            'finished_at' => now(),
            'signals_triaged' => $triaged,
            'prs_opened' => $prsOpened,
            'investigate_only' => $investigateOnly,
            'critical_count' => $criticalCount,
            'digest_summary' => mb_substr(implode("\n", $lines), 0, 3000),
        ]);

        $run->refresh();
        $digest->execute($run);
    }
}
