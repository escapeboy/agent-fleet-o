<?php

namespace App\Domain\Outbound\Enums;

enum OutboundChannel: string
{
    // Core channels — always available, each backed by a built-in connector
    // registered as a driver on OutboundConnectorManager.
    case Email = 'email';
    case Webhook = 'webhook';
    case Notification = 'notification';
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
        return true;
    }
}
