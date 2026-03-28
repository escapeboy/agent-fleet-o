<?php

namespace Tests\Unit\Infrastructure\Security;

use App\Infrastructure\Security\IpReputationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IpReputationServiceTest extends TestCase
{
    private IpReputationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new IpReputationService();
    }

    public function test_private_ipv4_addresses_bypass_check(): void
    {
        foreach (['127.0.0.1', '192.168.1.1', '10.0.0.1', '172.16.0.1'] as $ip) {
            $result = $this->service->check($ip);

            $this->assertTrue($this->service->isPrivate($ip), "Expected {$ip} to be private");
            $this->assertEquals(0, $result->abuseScore);
            $this->assertFalse($result->isTor);
        }
    }

    public function test_public_ip_fetches_from_abuseipdb_and_caches(): void
    {
        config(['security.ip_reputation.abuseipdb_key' => 'test-key']);

        Http::fake([
            'api.abuseipdb.com/*' => Http::response([
                'data' => [
                    'abuseConfidenceScore' => 85,
                    'isTor' => true,
                    'isWhitelisted' => false,
                    'usageType' => 'Tor Exit Node',
                    'countryCode' => 'DE',
                ],
            ], 200),
        ]);

        $result = $this->service->check('45.33.32.156');

        $this->assertEquals(85, $result->abuseScore);
        $this->assertTrue($result->isTor);
        $this->assertEquals('DE', $result->countryCode);
        $this->assertFalse($result->fromCache);

        // Second call should come from cache.
        $result2 = $this->service->check('45.33.32.156');
        $this->assertTrue($result2->fromCache);

        Http::assertSentCount(1);
    }

    public function test_fails_open_on_http_timeout(): void
    {
        config(['security.ip_reputation.abuseipdb_key' => 'test-key']);

        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        $result = $this->service->check('45.33.32.156');

        $this->assertEquals(0, $result->abuseScore);
        $this->assertFalse($result->isHighRisk(75));
    }

    public function test_fails_open_when_api_key_missing(): void
    {
        config(['security.ip_reputation.abuseipdb_key' => null]);

        $result = $this->service->check('45.33.32.156');

        $this->assertEquals(0, $result->abuseScore);
    }

    public function test_is_high_risk_threshold(): void
    {
        config(['security.ip_reputation.abuseipdb_key' => 'test-key']);

        Http::fake([
            'api.abuseipdb.com/*' => Http::response([
                'data' => ['abuseConfidenceScore' => 80, 'isTor' => false, 'isWhitelisted' => false, 'usageType' => '', 'countryCode' => 'US'],
            ], 200),
        ]);

        $result = $this->service->check('45.33.32.156');

        $this->assertTrue($result->isHighRisk(75));
        $this->assertFalse($result->isHighRisk(90));
    }
}
