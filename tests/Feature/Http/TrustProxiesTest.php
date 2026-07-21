<?php

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Guards against the `trustProxies(at: '*')` regression: Laravel reads '*' as
 * "trust only the immediate calling IP", so Symfony stops walking X-Forwarded-For
 * at the first untrusted hop (an internal proxy) and every visitor collapses onto
 * one address. Self-hosted deployments of this standalone entrypoint typically sit
 * behind a reverse proxy on a private network, so those ranges must be trusted
 * explicitly instead.
 */
class TrustProxiesTest extends TestCase
{
    // No RefreshDatabase — pure middleware/request-object assertions.

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/__trust-proxies-probe', fn () => response()->json([
            'ip' => request()->ip(),
            'secure' => request()->isSecure(),
        ]));
    }

    public function test_resolves_the_original_client_ip_through_the_full_proxy_chain(): void
    {
        $response = $this->call('GET', '/__trust-proxies-probe', server: [
            'REMOTE_ADDR' => '172.20.0.5',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.77, 172.24.0.23',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $response->assertOk();
        $response->assertJson(['ip' => '203.0.113.77']);
    }

    public function test_trusts_the_forwarded_proto_from_the_proxy_chain(): void
    {
        $response = $this->call('GET', '/__trust-proxies-probe', server: [
            'REMOTE_ADDR' => '172.20.0.5',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.77, 172.24.0.23',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $response->assertJson(['secure' => true]);
    }

    public function test_does_not_believe_a_forwarded_for_header_from_a_public_peer(): void
    {
        // A direct caller from a public IP is not a trusted proxy, so its own
        // X-Forwarded-For claim must be ignored — otherwise any caller could
        // spoof its reported IP.
        $response = $this->call('GET', '/__trust-proxies-probe', server: [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ]);

        $response->assertJson(['ip' => '203.0.113.9']);
    }
}
