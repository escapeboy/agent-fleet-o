<?php

namespace Tests\Unit\Domain\Outbound\Connectors;

use App\Domain\Outbound\Connectors\GoogleChatConnector;
use PHPUnit\Framework\TestCase;

class GoogleChatConnectorTest extends TestCase
{
    private GoogleChatConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connector = new GoogleChatConnector;
    }

    public function test_supports_google_chat_channel(): void
    {
        $this->assertTrue($this->connector->supports('google_chat'));
    }

    public function test_does_not_support_other_channels(): void
    {
        $this->assertFalse($this->connector->supports('telegram'));
        $this->assertFalse($this->connector->supports('slack'));
        $this->assertFalse($this->connector->supports('email'));
        $this->assertFalse($this->connector->supports('discord'));
        $this->assertFalse($this->connector->supports('whatsapp'));
        $this->assertFalse($this->connector->supports('teams'));
        $this->assertFalse($this->connector->supports('webhook'));
    }
}
