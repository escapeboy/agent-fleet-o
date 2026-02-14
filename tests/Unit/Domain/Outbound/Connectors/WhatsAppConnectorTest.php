<?php

namespace Tests\Unit\Domain\Outbound\Connectors;

use App\Domain\Outbound\Connectors\WhatsAppConnector;
use PHPUnit\Framework\TestCase;

class WhatsAppConnectorTest extends TestCase
{
    private WhatsAppConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connector = new WhatsAppConnector;
    }

    public function test_supports_whatsapp_channel(): void
    {
        $this->assertTrue($this->connector->supports('whatsapp'));
    }

    public function test_does_not_support_other_channels(): void
    {
        $this->assertFalse($this->connector->supports('telegram'));
        $this->assertFalse($this->connector->supports('slack'));
        $this->assertFalse($this->connector->supports('email'));
        $this->assertFalse($this->connector->supports('discord'));
        $this->assertFalse($this->connector->supports('webhook'));
    }
}
