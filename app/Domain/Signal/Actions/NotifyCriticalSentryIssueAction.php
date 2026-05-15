<?php

namespace App\Domain\Signal\Actions;

use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Services\SentryWatchdogNotifier;
use Illuminate\Support\Facades\Cache;

/**
 * Sends an immediate alert for a critical Sentry issue, bypassing the 2x/day
 * digest. Deduplicated per signal for 24h so a re-triaged issue does not
 * re-alert.
 */
class NotifyCriticalSentryIssueAction
{
    public function __construct(private readonly SentryWatchdogNotifier $notifier) {}

    public function execute(Signal $signal): bool
    {
        $dedupKey = "sentry_watchdog:critical:{$signal->id}";

        // Atomic add — returns false if a critical alert already went out.
        if (! Cache::add($dedupKey, true, now()->addDay())) {
            return false;
        }

        $payload = $signal->payload ?? [];
        $title = (string) ($payload['title'] ?? 'Untitled Sentry issue');
        $level = (string) ($payload['level'] ?? 'error');
        $events = (string) ($payload['count'] ?? '?');
        $permalink = (string) ($payload['permalink'] ?? '');

        $body = "\u{1F534} <b>Critical Sentry issue</b>\n"
            .e($title)."\n"
            .'Level: '.e($level).' · Events: '.e($events);

        if ($permalink !== '') {
            $body .= "\n".e($permalink);
        }

        return $this->notifier->sendToDigestChannel($signal->team_id, $body);
    }
}
