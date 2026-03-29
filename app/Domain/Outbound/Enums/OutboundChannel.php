<?php

namespace App\Domain\Outbound\Enums;

enum OutboundChannel: string
{
    // Core channels — always available, managed by OutboundConnectorManager
    case Email = 'email';
    case Webhook = 'webhook';
    case Notification = 'notification';

    // Legacy channels — kept for backward compatibility with existing data.
    // These are no longer registered as core connectors but can be added
    // by plugins via OutboundConnectorManager::extend() or handled by
    // agents via MCP tools (browser automation, API calls).
    case Telegram = 'telegram';
    case Slack = 'slack';
    case WhatsApp = 'whatsapp';
    case Discord = 'discord';
    case Teams = 'teams';
    case GoogleChat = 'google_chat';
    case SignalProtocol = 'signal_protocol';
    case Matrix = 'matrix';
    case SupabaseRealtime = 'supabase_realtime';
    case Ntfy = 'ntfy';

    /**
     * Whether this is a core channel with a built-in connector.
     */
    public function isCore(): bool
    {
        return in_array($this, [self::Email, self::Webhook, self::Notification]);
    }
}
