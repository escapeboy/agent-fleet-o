<?php

namespace Tests\Unit\Infrastructure\AI\Jobs;

use App\Domain\Shared\Services\SsrfGuard;
use App\Infrastructure\AI\Jobs\ExportToPhoenixJob;
use Mockery;
use Tests\TestCase;

/**
 * The OTel SDK's HTTP transport relies on `php-http/discovery` to find a
 * PSR-18 client at runtime — exercising that real path under PHPUnit needs
 * the full Guzzle stack and a live HTTP endpoint. Out of scope for unit
 * tests. We assert only the guard-rail behavior here; protobuf serialization
 * + transport are covered by the OTel SDK's own test suite and our prod
 * smoke test.
 */
class ExportToPhoenixJobTest extends TestCase
{
    public function test_early_returns_on_empty_endpoint(): void
    {
        $job = new ExportToPhoenixJob(
            endpoint: '',
            spanName: 'test',
            attributes: [],
            startNanos: 1,
            endNanos: 2,
        );

        $job->handle(Mockery::mock(SsrfGuard::class));

        $this->expectNotToPerformAssertions();
    }

    public function test_blocks_non_https_when_allow_http_off(): void
    {
        config(['llmops.phoenix.allow_http' => false]);

        $ssrf = Mockery::mock(SsrfGuard::class);
        $ssrf->shouldNotReceive('assertPublicUrl');

        $job = new ExportToPhoenixJob(
            endpoint: 'http://phoenix:6006',
            spanName: 'test',
            attributes: [],
            startNanos: 1,
            endNanos: 2,
        );

        $job->handle($ssrf);

        // No exception, no SSRF call, no transport spun up — early return path.
        $this->assertTrue(true);
    }

    public function test_https_endpoint_invokes_ssrf_guard(): void
    {
        config(['llmops.phoenix.allow_http' => false]);

        $ssrf = Mockery::mock(SsrfGuard::class);
        $ssrf->shouldReceive('assertPublicUrl')
            ->once()
            ->with('https://phoenix.example.com/v1/traces')
            ->andThrow(new \RuntimeException('blocked by ssrf'));

        $job = new ExportToPhoenixJob(
            endpoint: 'https://phoenix.example.com',
            spanName: 'test',
            attributes: ['llm.model_name' => 'claude'],
            startNanos: 1,
            endNanos: 2,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('blocked by ssrf');

        $job->handle($ssrf);
    }
}
