<?php

namespace Tests\Unit\Domain\Signal;

use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Connectors\ScreenpipeConnector;
use Tests\TestCase;

class ScreenpipeConnectorTest extends TestCase
{
    public function test_supports_screenpipe_driver(): void
    {
        $connector = new ScreenpipeConnector($this->createMock(IngestSignalAction::class));

        $this->assertTrue($connector->supports('screenpipe'));
        $this->assertFalse($connector->supports('rss'));
    }

    public function test_get_driver_name(): void
    {
        $connector = new ScreenpipeConnector($this->createMock(IngestSignalAction::class));

        $this->assertSame('screenpipe', $connector->getDriverName());
    }

    public function test_rejects_non_loopback_url(): void
    {
        $connector = new ScreenpipeConnector($this->createMock(IngestSignalAction::class));

        // Non-loopback should return empty (SSRF protection)
        $result = $connector->poll(['base_url' => 'http://169.254.169.254']);
        $this->assertSame([], $result);

        $result = $connector->poll(['base_url' => 'http://internal-service:3030']);
        $this->assertSame([], $result);

        $result = $connector->poll(['base_url' => 'http://10.0.0.1:3030']);
        $this->assertSame([], $result);
    }

    public function test_get_updated_config_sets_timestamp(): void
    {
        $connector = new ScreenpipeConnector($this->createMock(IngestSignalAction::class));

        $config = ['base_url' => 'http://localhost:3030'];
        $updated = $connector->getUpdatedConfig($config, ['signal1']);

        $this->assertArrayHasKey('_last_timestamp', $updated);
    }

    public function test_get_updated_config_preserves_without_signals(): void
    {
        $connector = new ScreenpipeConnector($this->createMock(IngestSignalAction::class));

        $config = ['base_url' => 'http://localhost:3030'];
        $updated = $connector->getUpdatedConfig($config, []);

        $this->assertArrayNotHasKey('_last_timestamp', $updated);
    }
}
