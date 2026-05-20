<?php

namespace App\Domain\Signal\Services;

use App\Domain\Outbound\Services\OutboundCredentialResolver;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Mail\SentryWatchdogMail;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends Sentry Watchdog messages (digests, critical alerts) to the configured
 * digest channel — email (platform mailer) or telegram (direct Bot API).
 *
 * The Outbound domain's OutboundProposal pipeline is experiment-scoped
 * (proposals require a non-null experiment_id), so a watchdog notification —
 * which has no experiment — is sent directly here.
 *
 * Failures are logged, never thrown — a notification problem must not abort a
 * watchdog run.
 */
class SentryWatchdogNotifier
{
    public function __construct(private readonly OutboundCredentialResolver $credentials) {}

    public function sendToDigestChannel(string $teamId, string $body, string $subject = 'FleetQ Sentry Watchdog'): bool
    {
        $channel = (string) config('sentry_watchdog.digest_channel', 'email');

        return match ($channel) {
            'email' => $this->sendEmail($teamId, $body, $subject),
            'telegram' => $this->sendTelegram($teamId, $body),
            default => $this->unsupported($channel),
        };
    }

    /**
     * Sends via the platform mailer to the configured digest recipient
     * (or the team owner when none is configured).
     */
    private function sendEmail(string $teamId, string $body, string $subject): bool
    {
        try {
            $recipient = config('sentry_watchdog.digest_email')
                ?: User::find(Team::ownerIdFor($teamId))?->email;

            if (! $recipient) {
                Log::warning('Sentry watchdog: no digest email recipient resolvable', [
                    'team_id' => $teamId,
                ]);

                return false;
            }

            Mail::to($recipient)->send(new SentryWatchdogMail($subject, nl2br($body)));

            return true;
        } catch (\Throwable $e) {
            Log::warning('Sentry watchdog email notification failed', [
                'team_id' => $teamId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Sends Telegram directly via the Bot API — bot_token AND chat_id both come
     * from the team-scoped credential resolver, never a process-global config.
     */
    private function sendTelegram(string $teamId, string $body): bool
    {
        try {
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
            Log::warning('Sentry watchdog telegram notification failed', [
                'team_id' => $teamId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function unsupported(string $channel): bool
    {
        Log::warning('Sentry watchdog: unsupported digest channel', ['channel' => $channel]);

        return false;
    }
}
