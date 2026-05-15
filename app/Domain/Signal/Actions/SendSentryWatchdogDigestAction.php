<?php

namespace App\Domain\Signal\Actions;

use App\Domain\Signal\Models\SentryWatchdogRun;
use App\Domain\Signal\Services\SentryWatchdogNotifier;

/**
 * Sends the batch digest for a finished Sentry Watchdog run to the configured
 * digest channel.
 */
class SendSentryWatchdogDigestAction
{
    public function __construct(private readonly SentryWatchdogNotifier $notifier) {}

    public function execute(SentryWatchdogRun $run): bool
    {
        $project = $run->integration->name;

        $body = "\u{1F6F0} <b>Sentry Watchdog</b> — ".e($project)."\n"
            .'Triaged: '.$run->signals_triaged
            .' · PRs opened: '.$run->prs_opened
            .' · Investigate-only: '.$run->investigate_only
            .' · Critical: '.$run->critical_count;

        $summary = trim((string) ($run->digest_summary ?? ''));
        if ($summary !== '') {
            $body .= "\n\n".e($summary);
        }

        return $this->notifier->sendToDigestChannel(
            $run->team_id,
            $body,
            'FleetQ Sentry Watchdog — '.$project,
        );
    }
}
