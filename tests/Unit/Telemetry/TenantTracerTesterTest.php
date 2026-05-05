<?php

namespace Tests\Unit\Telemetry;

use App\Infrastructure\Telemetry\TenantTracerTester;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TenantTracerTesterTest extends TestCase
{
    private TenantTracerTester $tester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tester = app(TenantTracerTester::class);
    }

    public function test_not_configured_when_endpoint_empty(): void
    {
        $result = $this->tester->test([]);
        $this->assertFalse($result['ok']);
        $this->assertSame('not_configured', $result['status']);
    }

    public function test_invalid_url_rejected(): void
    {
        $result = $this->tester->test(['endpoint' => 'not a url']);
        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_endpoint', $result['status']);
    }

    public function test_non_http_scheme_rejected(): void
    {
        $result = $this->tester->test(['endpoint' => 'ftp://example.com']);
        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_scheme', $result['status']);
    }

    public function test_accepts_200_response(): void
    {
        Http::fake([
            'example.com/v1/traces' => Http::response('', 200),
        ]);

        $result = $this->tester->test(['endpoint' => 'https://example.com']);

        $this->assertTrue($result['ok']);
        $this->assertSame('ok', $result['status']);
        $this->assertSame(200, $result['http_status']);
        $this->assertIsInt($result['latency_ms']);
    }

    public function test_accepts_202_response(): void
    {
        Http::fake([
            'example.com/v1/traces' => Http::response('', 202),
        ]);
        $result = $this->tester->test(['endpoint' => 'https://example.com']);
        $this->assertTrue($result['ok']);
        $this->assertSame('ok', $result['status']);
    }

    public function test_empty_probe_400_still_means_auth_valid(): void
    {
        Http::fake([
            'example.com/v1/traces' => Http::response('protobuf parse error', 400),
        ]);

        $result = $this->tester->test(['endpoint' => 'https://example.com']);

        $this->assertTrue($result['ok'], 'HTTP 400 on empty probe is expected — proves auth works');
        $this->assertSame('ok_auth_valid', $result['status']);
    }

    public function test_401_reports_auth_failed(): void
    {
        Http::fake([
            'example.com/v1/traces' => Http::response('', 401),
        ]);

        $result = $this->tester->test(['endpoint' => 'https://example.com']);

        $this->assertFalse($result['ok']);
        $this->assertSame('auth_failed', $result['status']);
        $this->assertSame(401, $result['http_status']);
    }

    public function test_404_reports_endpoint_not_found(): void
    {
        Http::fake([
            'example.com/v1/traces' => Http::response('', 404),
        ]);
        $result = $this->tester->test(['endpoint' => 'https://example.com']);
        $this->assertFalse($result['ok']);
        $this->assertSame('endpoint_not_found', $result['status']);
    }

    public function test_500_reports_collector_error(): void
    {
        Http::fake([
            'example.com/v1/traces' => Http::response('', 503),
        ]);
        $result = $this->tester->test(['endpoint' => 'https://example.com']);
        $this->assertFalse($result['ok']);
        $this->assertSame('collector_error', $result['status']);
    }

    public function test_unreachable_endpoint_fails(): void
    {
        Http::fake(function () {
            throw new ConnectionException('dns lookup failed');
        });
        $result = $this->tester->test(['endpoint' => 'https://example.com']);
        $this->assertFalse($result['ok']);
        $this->assertSame('unreachable', $result['status']);
        $this->assertStringContainsString('dns lookup failed', $result['message']);
    }

    public function test_token_is_injected_as_bearer(): void
    {
        Http::fake([
            'example.com/v1/traces' => Http::response('', 200),
        ]);

        $this->tester->test([
            'endpoint' => 'https://example.com',
            'otlp_token_encrypted' => Crypt::encryptString('raw-token-xyz'),
        ]);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer raw-token-xyz');
        });
    }

    public function test_prefixed_token_not_double_wrapped(): void
    {
        Http::fake([
            'example.com/v1/traces' => Http::response('', 200),
        ]);

        $this->tester->test([
            'endpoint' => 'https://example.com',
            'otlp_token_encrypted' => Crypt::encryptString('Basic user=x'),
        ]);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Basic user=x');
        });
    }

    public function test_corrupt_ciphertext_returns_token_corrupt(): void
    {
        $result = $this->tester->test([
            'endpoint' => 'https://example.com',
            'otlp_token_encrypted' => 'not-a-valid-ciphertext',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('token_corrupt', $result['status']);
    }
}
