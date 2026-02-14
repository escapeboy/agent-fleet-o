<?php

namespace Tests\Unit\Domain\Outbound\Connectors;

use App\Domain\Outbound\Connectors\DiscordConnector;
use PHPUnit\Framework\TestCase;

class DiscordConnectorTest extends TestCase
{
    private DiscordConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connector = new DiscordConnector;
    }

    public function test_supports_discord_channel(): void
    {
        $this->assertTrue($this->connector->supports('discord'));
    }

    public function test_does_not_support_other_channels(): void
    {
        $this->assertFalse($this->connector->supports('telegram'));
        $this->assertFalse($this->connector->supports('slack'));
        $this->assertFalse($this->connector->supports('email'));
        $this->assertFalse($this->connector->supports('whatsapp'));
        $this->assertFalse($this->connector->supports('webhook'));
    }
}
