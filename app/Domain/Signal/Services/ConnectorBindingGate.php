<?php

namespace App\Domain\Signal\Services;

use App\Domain\Outbound\Actions\SendOutboundAction;
use App\Domain\Outbound\Enums\OutboundChannel;
use App\Domain\Outbound\Enums\OutboundProposalStatus;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Signal\Enums\ConnectorBindingStatus;
use App\Domain\Signal\Models\ConnectorBinding;
use App\Domain\Telegram\Models\TelegramBot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Guards inbound signals from unknown/unvetted senders.
 *
 * For each (team, channel, external_id) tuple, checks whether the sender
 * is approved, pending, or blocked. Unknown senders are placed into
 * `pending` state and sent a pairing code reply.
 *
 * Returns 'approved' | 'pending' | 'blocked'.
 */
class ConnectorBindingGate
{
    /**
     * Source types that bypass the binding gate entirely.
     * These are internal platform signals that should never be blocked.
     */
    private const BYPASS_SOURCE_TYPES = [
        'platform',
        'manual',
        'webhook',
        'rss',
        'api_polling',
        'imap',
        'calendar',
        'github_issues',
        'github',
        'jira',
        'linear',
        'sentry',
        'datadog',
        'pagerduty',
        'http_monitor',
        'clearcue',
        'intent_score',
    ];

    /**
     * Check a sender's approval status and create a pending binding if unknown.
     *
     * @param  array<string, mixed>  $hints  Optional extras: ['name', 'phone', 'email']
     * @return 'approved'|'pending'|'blocked'
     */
    public function check(
        string $teamId,
        string $channel,
        string $externalId,
        array $hints = [],
    ): string {
        // Channels that require explicit binding approval
        if (in_array($channel, self::BYPASS_SOURCE_TYPES, true)) {
            return 'approved';
        }

        $binding = ConnectorBinding::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('channel', $channel)
            ->where('external_id', $externalId)
            ->first();

        if ($binding === null) {
            // Unknown sender — create a pending binding with a pairing code
            $code = ConnectorBinding::generatePairingCode();

            $binding = ConnectorBinding::create([
                'team_id' => $teamId,
                'channel' => $channel,
                'external_id' => $externalId,
                'external_name' => $hints['name'] ?? null,
                'status' => ConnectorBindingStatus::Pending,
                'pairing_code' => $code,
                'pairing_code_expires_at' => now()->addHours(24),
                'metadata' => array_filter([
                    'phone' => $hints['phone'] ?? null,
                    'email' => $hints['email'] ?? null,
                ]),
            ]);

            $this->sendPairingReply($teamId, $channel, $externalId, $code);

            Log::info('ConnectorBindingGate: new sender placed in pending state', [
                'team_id' => $teamId,
                'channel' => $channel,
                'external_id' => $externalId,
                'binding_id' => $binding->id,
            ]);

            return 'pending';
        }

        return $binding->status->value;
    }

    /**
     * Send a channel-specific pairing code reply to the unknown sender.
     */
    private function sendPairingReply(
        string $teamId,
        string $channel,
        string $externalId,
        string $code,
    ): void {
        $message = "Your pairing code is: <b>{$code}</b>\n\n"
            ."Open FleetQ → Settings → Connector Bindings and enter this code to authorize your account.\n"
            .'Code expires in 24 hours.';

        try {
            match ($channel) {
                'telegram' => $this->sendTelegramReply($teamId, $externalId, $message),
                'whatsapp',
                'discord',
                'matrix',
                'signal_protocol' => $this->sendOutboundPairingReply($teamId, $channel, $externalId, $message),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::warning('ConnectorBindingGate: failed to send pairing reply', [
                'channel' => $channel,
                'external_id' => $externalId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendTelegramReply(string $teamId, string $chatId, string $message): void
    {
        $bot = TelegramBot::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('status', 'active')
            ->first();

        if (! $bot) {
            return;
        }

        Http::timeout(5)->post($bot->apiUrl('sendMessage'), [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'HTML',
        ]);
    }

    private function sendOutboundPairingReply(
        string $teamId,
        string $channel,
        string $externalId,
        string $message,
    ): void {
        // Build a channel-appropriate target so each outbound connector
        // can locate the recipient from the externalId it already knows.
        $target = match ($channel) {
            'whatsapp' => ['phone' => $externalId],
            'signal_protocol' => ['recipient' => $externalId],
            'matrix' => ['room_id' => $externalId],
            default => ['recipient' => $externalId],
        };

        $proposal = OutboundProposal::withoutGlobalScopes()->create([
            'team_id' => $teamId,
            'channel' => OutboundChannel::from($channel),
            'target' => $target,
            'content' => ['text' => $message],
            'status' => OutboundProposalStatus::Approved,
        ]);

        app(SendOutboundAction::class)->execute($proposal);
    }
}
