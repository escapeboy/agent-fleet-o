<?php

namespace Tests\Feature\Domain\Outbound;

use App\Domain\Outbound\Connectors\DummyConnector;
use App\Domain\Outbound\Connectors\NotificationConnector;
use App\Domain\Outbound\Connectors\SmtpEmailConnector;
use App\Domain\Outbound\Connectors\WebhookOutboundConnector;
use App\Domain\Outbound\Contracts\OutboundConnectorInterface;
use App\Domain\Outbound\Managers\OutboundConnectorManager;
use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Outbound\Models\OutboundProposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutboundConnectorManagerTest extends TestCase
{
    use RefreshDatabase;

    private OutboundConnectorManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = app(OutboundConnectorManager::class);
    }

    public function test_default_driver_is_email(): void
    {
        $this->assertSame('email', $this->manager->getDefaultDriver());
    }

    public function test_resolves_email_driver(): void
    {
        $connector = $this->manager->driver('email');
        $this->assertInstanceOf(SmtpEmailConnector::class, $connector);
    }

    public function test_resolves_webhook_driver(): void
    {
        $connector = $this->manager->driver('webhook');
        $this->assertInstanceOf(WebhookOutboundConnector::class, $connector);
    }

    public function test_resolves_notification_driver(): void
    {
        $connector = $this->manager->driver('notification');
        $this->assertInstanceOf(NotificationConnector::class, $connector);
    }

    public function test_resolves_dummy_driver(): void
    {
        $connector = $this->manager->driver('dummy');
        $this->assertInstanceOf(DummyConnector::class, $connector);
    }

    public function test_connector_for_returns_dummy_for_unknown_channel(): void
    {
        $connector = $this->manager->connectorFor('nonexistent_channel');
        $this->assertInstanceOf(DummyConnector::class, $connector);
    }

    public function test_connector_for_returns_real_connector_for_known_channel(): void
    {
        $connector = $this->manager->connectorFor('email');
        $this->assertInstanceOf(SmtpEmailConnector::class, $connector);
    }

    public function test_has_connector_returns_true_for_core_channels(): void
    {
        $this->assertTrue($this->manager->hasConnector('email'));
        $this->assertTrue($this->manager->hasConnector('webhook'));
        $this->assertTrue($this->manager->hasConnector('notification'));
    }

    public function test_has_connector_returns_false_for_unknown_channel(): void
    {
        $this->assertFalse($this->manager->hasConnector('nonexistent'));
    }

    public function test_has_connector_returns_false_for_dummy(): void
    {
        $this->assertFalse($this->manager->hasConnector('dummy'));
    }

    public function test_extend_registers_plugin_connector(): void
    {
        $pluginConnector = new class implements OutboundConnectorInterface
        {
            public function send(OutboundProposal $proposal): OutboundAction
            {
                throw new \RuntimeException('Not implemented');
            }

            public function supports(string $channel): bool
            {
                return $channel === 'custom_plugin';
            }
        };

        $this->manager->extend('custom_plugin', fn () => $pluginConnector);

        $resolved = $this->manager->driver('custom_plugin');
        $this->assertSame($pluginConnector, $resolved);
        $this->assertTrue($this->manager->hasConnector('custom_plugin'));
    }
}
