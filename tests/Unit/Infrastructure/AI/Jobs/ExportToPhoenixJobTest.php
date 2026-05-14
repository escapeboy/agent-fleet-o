<?php

namespace Tests\Unit\Infrastructure\AI\Jobs;

use App\Domain\Shared\Services\SsrfGuard;
use App\Infrastructure\AI\Jobs\ExportToPhoenixJob;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ExportToPhoenixJobTest extends TestCase
{
    public function test_early_returns_on_empty_endpoint(): void
    {
        Http::fake();

        $job = new ExportToPhoenixJob(payload: ['resourceSpans' => []], endpoint: '');
        $job->handle(Mockery::mock(SsrfGuard::class));

        Http::assertNothingSent();
    }

    public function test_blocks_non_https_when_allow_http_off(): void
    {
        config(['llmops.phoenix.allow_http' => false]);
        Http::fake();

        $job = new ExportToPhoenixJob(payload: ['resourceSpans' => []], endpoint: 'http://phoenix:6006');
        $job->handle(Mockery::mock(SsrfGuard::class));

        Http::assertNothingSent();
    }

    public function test_allows_http_when_allow_http_on(): void
    {
        config(['llmops.phoenix.allow_http' => true]);
        Http::fake();

        $ssrf = Mockery::mock(SsrfGuard::class);
        $ssrf->shouldNotReceive('assertPublicUrl'); // private docker host skips SSRF check

        $job = new ExportToPhoenixJob(payload: ['resourceSpans' => []], endpoint: 'http://phoenix:6006');
        $job->handle($ssrf);

        Http::assertSent(fn ($request) => $request->url() === 'http://phoenix:6006/v1/traces');
    }

    public function test_attaches_authorization_header_when_api_key_set(): void
    {
        config(['llmops.phoenix.allow_http' => true]);
        Http::fake();

        $job = new ExportToPhoenixJob(
            payload: ['resourceSpans' => []],
            endpoint: 'http://phoenix:6006',
            apiKey: 'secret-123',
        );
        $job->handle(Mockery::mock(SsrfGuard::class));

        Http::assertSent(
            fn ($request) => $request->hasHeader('Authorization', 'Bearer secret-123'),
        );
    }

    public function test_https_endpoint_invokes_ssrf_guard(): void
    {
        config(['llmops.phoenix.allow_http' => false]);
        Http::fake();

        $ssrf = Mockery::mock(SsrfGuard::class);
        $ssrf->shouldReceive('assertPublicUrl')->once()->with('https://phoenix.example.com/v1/traces');

        $job = new ExportToPhoenixJob(
            payload: ['resourceSpans' => []],
            endpoint: 'https://phoenix.example.com',
        );
        $job->handle($ssrf);

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://phoenix.example.com/v1/traces'));
    }
}
