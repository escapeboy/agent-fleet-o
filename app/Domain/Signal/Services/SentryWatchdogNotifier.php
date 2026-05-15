<?php

namespace App\Domain\Signal\Services;

use App\Domain\Outbound\Services\OutboundCredentialResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends Sentry Watchdog messages (digests, critical alerts) to the configured
 * digest channel.
 *
 * The Outbound domain's OutboundProposal pipeline is experiment-scoped
 * (proposals require a non-null experiment_id), so a watchdog notification —
 * which has no experiment — is sent directly. Telegram is the only wired
 * channel for this sprint.
 *
 * Failures are logged, never thrown — a notification problem must not abort a
 * watchdog run.
 */
class SentryWatchdogNotifier
{
    public function __construct(private readonly OutboundCredentialResolver $credentials) {}

    public function sendToDigestChannel(string $teamId, string $body): bool
    {
        $channel = (string) config('sentry_watchdog.digest_channel', 'telegram');

        if ($channel !== 'telegram') {
            Log::warning('Sentry watchdog: unsupported digest channel', ['channel' => $channel]);

            return false;
        }

        try {
            // bot_token AND chat_id both come from the team-scoped credential
            // resolver — never a process-global config, which would cross-deliver
            // one team's alerts to another on a multi-team install.
            $creds = $this->credentials->resolve('telegram', null, $teamId);

            $botToken = $creds['bot_token'] ?? null;
            $chatId = $creds['chat_id'] ?? null;

            if (! $botToken || ! $chatId) {
                Log::warning('Sentry watchdog: Telegram not configured for digest channel', [
                    'team_id' => $teamId,
                ]);

                return false;
            }

            $response = Http::timeout(15)->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $body,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

            return $response->successful() && $response->json('ok') === true;
        } catch (\Throwable $e) {
            Log::warning('Sentry watchdog notification failed', [
                'team_id' => $teamId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
