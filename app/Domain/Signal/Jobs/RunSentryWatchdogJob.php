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
use Illuminate\Support\Collection;
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
            ->where('source_identifier', 'sentry')
            ->whereNull('experiment_id')
            ->whereNotIn('status', [SignalStatus::Resolved->value, SignalStatus::Dismissed->value])
            ->whereNull('payload->sentry_watchdog_triaged_at')
            ->orderBy('created_at')
            ->limit((int) config('sentry_watchdog.max_signals_per_run', 15))
            ->get();

        // Drop known Sentry noise (e.g. Cron-monitor boilerplate) before triage
        // so the LLM never burns credits on signals that produce no actionable
        // result. Filter applied in PHP because SQLite (the test driver) lacks
        // ILIKE and the result set is already capped at max_signals_per_run.
        $signals = $this->dropIgnoredTitles(
            $signals,
            (array) config('sentry_watchdog.ignore_title_patterns', []),
        );

        // Group by Sentry issue id so a re-ingested issue is triaged once.
        // Integration signals wrap the driver item: the stable id is payload.source_id.
        $groups = $signals->groupBy(fn (Signal $signal) => $signal->payload['source_id'] ?? $signal->id);

        $triaged = 0;
        $prsOpened = 0;
        $investigateOnly = 0;
        $criticalCount = 0;
        $delegationFailures = 0;
        $lines = [];
        $criticalLines = [];
        $prsCap = (int) config('sentry_watchdog.max_prs_per_run', 3);
        $quotaReached = false;

        // Projects whose code lives in a single repo outside the agent-fleet
        // monorepo declare it here; suspect files then route to that one repo
        // instead of the base/parent split. Null → agent-fleet monorepo default.
        $configuredRepo = data_get($integration->config, 'target_repository');
        $targetRepositoryOverride = is_string($configuredRepo) ? $configuredRepo : null;

        foreach ($groups as $group) {
            /** @var Signal $signal */
            $signal = $group->first();

            // Hard cap on delegations per run. Once reached, remaining signals
            // stay pending (unstamped) for the next run — both to keep the
            // human reviewer's PR queue manageable and to limit Anthropic Max
            // bill exposure when claude-code-vps misbehaves.
            if ($prsOpened >= $prsCap) {
                $quotaReached = true;
                break;
            }

            try {
                $result = $triage->execute($signal, $targetRepositoryOverride);
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
                $criticalLines[] = $this->formatCriticalLine($signal, $result->summary);
            }

            if ($result->outcome === SentryTriageOutcome::Delegated) {
                $prsOpened++;
                $triaged++;
            } elseif ($result->outcome === SentryTriageOutcome::InvestigateOnly) {
                $investigateOnly++;
                $triaged++;
            } elseif ($result->outcome === SentryTriageOutcome::Failed) {
                // A Failed outcome (e.g. delegation threw) used to be silently
                // dropped here, leaving the signal pending and re-attempted every
                // run with zero trace in the metrics. Surface it in the digest so
                // a broken delegation path is visible instead of an invisible
                // 0-PR loop. The signal stays unstamped so a deployed fix retries it.
                $delegationFailures++;
                $lines[] = '• [FAILED] '.($result->summary ?? 'delegation error').' — not delegated';

                continue;
            } else {
                // Skipped — idempotency guard (already delegated/terminal). Expected, silent.
                continue;
            }

            $lines[] = '• ['.strtoupper($result->tier->value).'] '
                .($result->summary ?? 'Sentry issue')
                .' — '.($result->wasDelegated() ? 'PR opened' : 'investigate-only');
        }

        if ($quotaReached) {
            $lines[] = '• [QUOTA] PR cap of '.$prsCap.' reached; remaining signals deferred to next run.';
        }

        if ($delegationFailures > 0) {
            $lines[] = '• [WARN] '.$delegationFailures.' delegation(s) failed this run — see [FAILED] lines above.';
        }

        $run->update([
            'finished_at' => now(),
            'signals_triaged' => $triaged,
            'prs_opened' => $prsOpened,
            'investigate_only' => $investigateOnly,
            'critical_count' => $criticalCount,
            'digest_summary' => mb_substr($this->composeDigestSummary($criticalLines, $lines), 0, 3000),
        ]);

        $run->refresh();
        $digest->execute($run);
    }

    /**
     * Drop signals whose Sentry issue title matches any configured ignore
     * pattern. Patterns use SQL ILIKE syntax (`%` wildcard) for parity with
     * the surrounding codebase; comparison is case-insensitive.
     *
     * @param  Collection<int, Signal>  $signals
     * @param  list<string>  $patterns
     * @return Collection<int, Signal>
     */
    private function dropIgnoredTitles($signals, array $patterns)
    {
        if ($patterns === []) {
            return $signals;
        }

        // Convert ILIKE patterns to regular expressions once.
        $regexes = array_map(
            static function (string $pattern): string {
                // Translate SQL `%` (multi) and `_` (single) wildcards to regex.
                $escaped = preg_quote($pattern, '/');
                $regex = str_replace(['%', '_'], ['.*', '.'], $escaped);

                return '/^'.$regex.'$/i';
            },
            $patterns,
        );

        return $signals->reject(function (Signal $signal) use ($regexes): bool {
            $title = (string) ($signal->payload['payload']['title'] ?? '');
            foreach ($regexes as $regex) {
                if (preg_match($regex, $title) === 1) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /**
     * Build the persistent digest text. Critical issues are rendered as a
     * header section so they are the first thing both the Telegram message
     * and the SentryWatchdogPage UI surface.
     *
     * @param  list<string>  $criticalLines
     * @param  list<string>  $lines
     */
    private function composeDigestSummary(array $criticalLines, array $lines): string
    {
        $sections = [];

        if ($criticalLines !== []) {
            $sections[] = "\u{1F534} Critical issues:\n".implode("\n", $criticalLines);
        }

        if ($lines !== []) {
            $sections[] = implode("\n", $lines);
        }

        return implode("\n\n", $sections);
    }

    /**
     * One bullet for the critical-issues section. Pulls title + permalink from
     * the wrapped Sentry payload (`payload.payload`) so the user can jump to
     * the issue from Telegram or the watchdog UI.
     */
    private function formatCriticalLine(Signal $signal, ?string $summary): string
    {
        $raw = $signal->payload ?? [];
        $payload = (isset($raw['payload']) && is_array($raw['payload'])) ? $raw['payload'] : $raw;
        $title = trim((string) ($payload['title'] ?? $summary ?? 'Sentry issue'));
        $permalink = trim((string) ($payload['permalink'] ?? ''));

        $line = '🔴 '.$title;
        if ($permalink !== '') {
            $line .= ' — '.$permalink;
        }

        return $line;
    }
}
