<?php

namespace Tests\Feature\Infrastructure\AI;

use App\Infrastructure\AI\Exceptions\BridgeNotConnectedException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class BridgeNotConnectedExceptionReportingTest extends TestCase
{
    #[Test]
    public function bridge_not_connected_is_not_reported(): void
    {
        $handler = app(ExceptionHandler::class);

        $this->assertFalse($handler->shouldReport(BridgeNotConnectedException::notConnected()));
        $this->assertFalse($handler->shouldReport(BridgeNotConnectedException::reconnectTimeout(30)));
        $this->assertTrue($handler->shouldReport(new RuntimeException('x')));
    }

    #[Test]
    public function uses_bridge_matches_bridge_providers_only(): void
    {
        $this->assertTrue(BridgeNotConnectedException::usesBridge('bridge_llm'));
        $this->assertTrue(BridgeNotConnectedException::usesBridge('bridge_agent'));
        $this->assertFalse(BridgeNotConnectedException::usesBridge('anthropic'));
        $this->assertFalse(BridgeNotConnectedException::usesBridge(''));
    }
}
