<?php

namespace App\Domain\Signal\Actions;

use App\Domain\Signal\Models\SentryWatchdogRun;
use App\Domain\Signal\Services\SentryWatchdogNotifier;
use Illuminate\Support\Collection;

/**
 * Sends ONE digest for a team's finished Sentry Watchdog runs (one run per Sentry project). Only projects where
 * something happened get a section; projects with nothing new are counted in one line. When nothing happened
 * anywhere, no message is sent. Every run passed in is marked as digested either way.
 */
class SendSentryWatchdogDigestAction
{
    private const MAX_BODY = 3900; // Telegram's limit is 4096 characters

    public function __construct(private readonly SentryWatchdogNotifier $notifier) {}

    /**
     * @param  Collection<int, SentryWatchdogRun>  $runs  finished runs of one team, not yet digested
     */
    public function execute(string $teamId, Collection $runs): bool
    {
        if ($runs->isEmpty()) {
            return false;
        }

        $active = $runs->filter(fn (SentryWatchdogRun $run) => $run->signals_triaged > 0 || $run->prs_opened > 0
            || $run->critical_count > 0);
        $quiet = $runs->count() - $active->count();

        $sent = false;
        if ($active->isNotEmpty()) {
            $sections = $active->sortByDesc('critical_count')->map(function (SentryWatchdogRun $run) {
                $section = '<b>'.e($run->integration->name ?? $run->integration_id).'</b> — '
                    .'Triaged: '.$run->signals_triaged
                    .' · PRs opened: '.$run->prs_opened
                    .' · Investigate-only: '.$run->investigate_only
                    .' · Critical: '.$run->critical_count;
                $summary = trim((string) ($run->digest_summary ?? ''));

                return $summary !== '' ? $section."\n".e(mb_substr($summary, 0, 600)) : $section;
            });

            $body = "\u{1F6F0} <b>Sentry Watchdog</b> — ".$active->count().' of '.$runs->count().' projects with news';
            foreach ($sections as $section) {
                if (mb_strlen($body) + mb_strlen($section) + 2 > self::MAX_BODY) {
                    $body .= "\n\n… and more on the Sentry Watchdog page.";
                    break;
                }
                $body .= "\n\n".$section;
            }
            if ($quiet > 0) {
                $body .= "\n\nNo new issues: ".$quiet.' projects.';
            }

            $sent = $this->notifier->sendToDigestChannel($teamId, $body, 'FleetQ Sentry Watchdog');
        }

        SentryWatchdogRun::withoutGlobalScopes()
            ->whereIn('id', $runs->pluck('id'))
            ->update(['digest_sent_at' => now()]);

        return $sent;
    }
}
