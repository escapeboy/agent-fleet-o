<?php

namespace Tests\Feature\Security;

use App\Infrastructure\Security\DTOs\IpReputationResult;
use App\Infrastructure\Security\IpReputationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IpReputationWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function stubIpReputation(int $abuseScore = 0, bool $isTor = false): void
    {
        $this->app->instance(IpReputationService::class, new class($abuseScore, $isTor) extends IpReputationService {
            public function __construct(
                private int $score,
                private bool $tor,
            ) {}

            public function check(string $ip): IpReputationResult
            {
                return new IpReputationResult(
                    ip: $ip,
                    abuseScore: $this->score,
                    isTor: $this->tor,
                    isVpn: false,
                    countryCode: 'US',
                    fromCache: false,
                );
            }

            public function isPrivate(string $ip): bool
            {
                return false;
            }
        });
    }

    public function test_high_risk_ip_is_blocked_when_enforcement_enabled(): void
    {
        config([
            'security.ip_reputation.enabled' => true,
            'security.ip_reputation.block_threshold' => 75,
            'security.ip_reputation.log_only' => false,
            'services.signal_webhook.secret' => null,
        ]);

        $this->stubIpReputation(abuseScore: 90);

        $response = $this->postJson('/api/signals/webhook', ['event' => 'test']);

        $response->assertStatus(403)
            ->assertJsonFragment(['error' => 'high_risk_ip']);
    }

    public function test_high_risk_ip_is_logged_but_not_blocked_in_log_only_mode(): void
    {
        config([
            'security.ip_reputation.enabled' => true,
            'security.ip_reputation.block_threshold' => 75,
            'security.ip_reputation.log_only' => true,
            'services.signal_webhook.secret' => null,
        ]);

        $this->stubIpReputation(abuseScore: 90);

        // Should not return 403; payload empty → 422
        $response = $this->postJson('/api/signals/webhook', []);

        $response->assertStatus(422);
    }

    public function test_low_risk_ip_passes_through(): void
    {
        config([
            'security.ip_reputation.enabled' => true,
            'security.ip_reputation.block_threshold' => 75,
            'security.ip_reputation.log_only' => false,
            'services.signal_webhook.secret' => null,
        ]);

        $this->stubIpReputation(abuseScore: 10);

        // Empty payload → 422 (not blocked by IP check)
        $response = $this->postJson('/api/signals/webhook', []);

        $response->assertStatus(422);
    }

    public function test_ip_reputation_disabled_skips_check(): void
    {
        config([
            'security.ip_reputation.enabled' => false,
            'services.signal_webhook.secret' => null,
        ]);

        // Even a very high score should not block when feature is disabled.
        $this->stubIpReputation(abuseScore: 100);

        $response = $this->postJson('/api/signals/webhook', []);

        $response->assertStatus(422);
    }

    public function test_private_ip_always_passes(): void
    {
        config([
            'security.ip_reputation.enabled' => true,
            'security.ip_reputation.block_threshold' => 75,
            'security.ip_reputation.log_only' => false,
            'services.signal_webhook.secret' => null,
        ]);

        // Actual IpReputationService: 127.0.0.1 is private → score=0
        $this->postJson('/api/signals/webhook', [])
            ->assertStatus(422); // reaches empty-payload check, not IP block
    }
}
