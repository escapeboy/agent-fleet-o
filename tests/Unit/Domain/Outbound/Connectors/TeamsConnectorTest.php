<?php

namespace Tests\Unit\Domain\Outbound\Connectors;

use App\Domain\Outbound\Connectors\TeamsConnector;
use PHPUnit\Framework\TestCase;

class TeamsConnectorTest extends TestCase
{
    private TeamsConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connector = new TeamsConnector;
    }

    public function test_supports_teams_channel(): void
    {
        $this->assertTrue($this->connector->supports('teams'));
    }

    public function test_does_not_support_other_channels(): void
    {
        $this->assertFalse($this->connector->supports('telegram'));
        $this->assertFalse($this->connector->supports('slack'));
        $this->assertFalse($this->connector->supports('email'));
        $this->assertFalse($this->connector->supports('discord'));
        $this->assertFalse($this->connector->supports('whatsapp'));
        $this->assertFalse($this->connector->supports('webhook'));
    }
}
