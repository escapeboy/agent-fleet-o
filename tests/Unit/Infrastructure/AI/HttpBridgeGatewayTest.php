<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Domain\Bridge\Enums\BridgeConnectionStatus;
use App\Domain\Bridge\Models\BridgeConnection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HttpBridgeGatewayTest extends TestCase
{
    #[Test]
    public function bridge_connection_is_http_mode_when_endpoint_url_set(): void
    {
        $connection = new BridgeConnection();
        $connection->endpoint_url = 'https://abc.trycloudflare.com';

        $this->assertTrue($connection->isHttpMode());
        $this->assertFalse($connection->isRelayMode());
    }

    #[Test]
    public function bridge_connection_is_relay_mode_when_no_endpoint_url(): void
    {
        $connection = new BridgeConnection();
        $connection->endpoint_url = null;

        $this->assertFalse($connection->isHttpMode());
        $this->assertTrue($connection->isRelayMode());
    }

    #[Test]
    public function bridge_connection_is_relay_mode_when_endpoint_url_empty_string(): void
    {
        $connection = new BridgeConnection();
        $connection->endpoint_url = '';

        $this->assertFalse($connection->isHttpMode());
        $this->assertTrue($connection->isRelayMode());
    }
}
