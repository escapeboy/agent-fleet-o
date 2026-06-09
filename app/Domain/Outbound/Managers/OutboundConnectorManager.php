<?php

namespace App\Domain\Outbound\Managers;

use App\Domain\Outbound\Connectors\DiscordConnector;
use App\Domain\Outbound\Connectors\DummyConnector;
use App\Domain\Outbound\Connectors\EmailConnectorDispatcher;
use App\Domain\Outbound\Connectors\GoogleChatConnector;
use App\Domain\Outbound\Connectors\MatrixConnector;
use App\Domain\Outbound\Connectors\NotificationConnector;
use App\Domain\Outbound\Connectors\NtfyConnector;
use App\Domain\Outbound\Connectors\SignalProtocolConnector;
use App\Domain\Outbound\Connectors\SlackConnector;
use App\Domain\Outbound\Connectors\SupabaseRealtimeConnector;
use App\Domain\Outbound\Connectors\TeamsConnector;
use App\Domain\Outbound\Connectors\TelegramConnector;
use App\Domain\Outbound\Connectors\WebhookOutboundConnector;
use App\Domain\Outbound\Connectors\WhatsAppConnector;
use App\Domain\Outbound\Contracts\OutboundConnectorInterface;
use Illuminate\Support\Manager;

/**
 * Laravel Manager for outbound connector resolution.
 *
 * Core drivers: email, webhook, notification, whatsapp, ntfy, telegram, slack,
 * discord, teams, google_chat, matrix, signal_protocol, supabase_realtime, dummy.
 * Plugins extend via: $manager->extend('custom', fn ($app) => new CustomConnector);
 *
 * Usage:
 *   $connector = app(OutboundConnectorManager::class)->connectorFor('email');
 *   $connector->send($proposal);
 */
class OutboundConnectorManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return 'email';
    }

    protected function createEmailDriver(): OutboundConnectorInterface
    {
        // The email channel dispatches to SMTP or Resend per the team's config.
        return $this->container->make(EmailConnectorDispatcher::class);
    }

    protected function createWebhookDriver(): OutboundConnectorInterface
    {
        return $this->container->make(WebhookOutboundConnector::class);
    }

    protected function createNotificationDriver(): OutboundConnectorInterface
    {
        return $this->container->make(NotificationConnector::class);
    }

    protected function createWhatsappDriver(): OutboundConnectorInterface
    {
        return $this->container->make(WhatsAppConnector::class);
    }

    protected function createNtfyDriver(): OutboundConnectorInterface
    {
        return $this->container->make(NtfyConnector::class);
    }

    protected function createTelegramDriver(): OutboundConnectorInterface
    {
        return $this->container->make(TelegramConnector::class);
    }

    protected function createSlackDriver(): OutboundConnectorInterface
    {
        return $this->container->make(SlackConnector::class);
    }

    protected function createDiscordDriver(): OutboundConnectorInterface
    {
        return $this->container->make(DiscordConnector::class);
    }

    protected function createTeamsDriver(): OutboundConnectorInterface
    {
        return $this->container->make(TeamsConnector::class);
    }

    protected function createGoogleChatDriver(): OutboundConnectorInterface
    {
        return $this->container->make(GoogleChatConnector::class);
    }

    protected function createMatrixDriver(): OutboundConnectorInterface
    {
        return $this->container->make(MatrixConnector::class);
    }

    protected function createSignalProtocolDriver(): OutboundConnectorInterface
    {
        return $this->container->make(SignalProtocolConnector::class);
    }

    protected function createSupabaseRealtimeDriver(): OutboundConnectorInterface
    {
        return $this->container->make(SupabaseRealtimeConnector::class);
    }

    protected function createDummyDriver(): OutboundConnectorInterface
    {
        return new DummyConnector;
    }

    /**
     * Resolve connector by channel name.
     * Falls back to DummyConnector for unconfigured channels.
     */
    public function connectorFor(string $channel): OutboundConnectorInterface
    {
        try {
            return $this->driver($channel);
        } catch (\InvalidArgumentException) {
            return $this->driver('dummy');
        }
    }

    /**
     * Check if a real (non-dummy) connector exists for a channel.
     */
    public function hasConnector(string $channel): bool
    {
        try {
            $connector = $this->driver($channel);

            return ! $connector instanceof DummyConnector;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
