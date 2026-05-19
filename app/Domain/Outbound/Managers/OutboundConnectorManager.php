<?php

namespace App\Domain\Outbound\Managers;

use App\Domain\Outbound\Connectors\DummyConnector;
use App\Domain\Outbound\Connectors\EmailConnectorDispatcher;
use App\Domain\Outbound\Connectors\NotificationConnector;
use App\Domain\Outbound\Connectors\NtfyConnector;
use App\Domain\Outbound\Connectors\WebhookOutboundConnector;
use App\Domain\Outbound\Connectors\WhatsAppConnector;
use App\Domain\Outbound\Contracts\OutboundConnectorInterface;
use Illuminate\Support\Manager;

/**
 * Laravel Manager for outbound connector resolution.
 *
 * Core drivers: email, webhook, notification, dummy.
 * Plugins extend via: $manager->extend('telegram', fn ($app) => new TelegramConnector);
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
