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
    }

    #[Test]
    public function plain_runtime_exceptions_are_still_reported(): void
    {
        $this->assertTrue(app(ExceptionHandler::class)->shouldReport(new RuntimeException('x')));
    }
}
