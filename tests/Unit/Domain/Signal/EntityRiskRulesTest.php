<?php

namespace Tests\Unit\Domain\Signal;

use App\Domain\Shared\Models\ContactIdentity;
use App\Domain\Signal\DTOs\ContactRiskContext;
use App\Domain\Signal\Rules\E01DisposableEmailRule;
use App\Domain\Signal\Rules\E02NoContactDataRule;
use App\Domain\Signal\Rules\I01HighRiskIpRule;
use App\Domain\Signal\Rules\I02TorVpnIpRule;
use App\Domain\Signal\Rules\S01BurstActivityRule;
use App\Domain\Signal\Rules\S02NoVerifiedChannelRule;
use App\Infrastructure\Security\DTOs\IpReputationResult;
use Propaganistas\LaravelDisposableEmail\Facades\DisposableDomains;
use Tests\TestCase;

class EntityRiskRulesTest extends TestCase
{
    private function makeContext(array $overrides = []): ContactRiskContext
    {
        $contact = new ContactIdentity(array_merge([
            'email' => 'user@example.com',
            'phone' => null,
        ], $overrides['contact'] ?? []));

        return new ContactRiskContext(
            contact: $contact,
            ipReputation: $overrides['ipReputation'] ?? null,
            recentSignals: $overrides['recentSignals'] ?? [],
            signalCount: $overrides['signalCount'] ?? 0,
            channelTypes: $overrides['channelTypes'] ?? [],
            hasVerifiedChannel: $overrides['hasVerifiedChannel'] ?? false,
        );
    }

    // E01: Disposable email

    public function test_e01_triggers_for_disposable_email(): void
    {
        DisposableDomains::shouldReceive('isDisposable')->once()->with('user@mailinator.com')->andReturn(true);

        $ctx = $this->makeContext(['contact' => ['email' => 'user@mailinator.com']]);

        $this->assertTrue((new E01DisposableEmailRule)->evaluate($ctx));
    }

    public function test_e01_does_not_trigger_for_legitimate_email(): void
    {
        DisposableDomains::shouldReceive('isDisposable')->once()->with('user@example.com')->andReturn(false);

        $ctx = $this->makeContext();

        $this->assertFalse((new E01DisposableEmailRule)->evaluate($ctx));
    }

    public function test_e01_does_not_trigger_when_email_is_null(): void
    {
        $ctx = $this->makeContext(['contact' => ['email' => null]]);

        $this->assertFalse((new E01DisposableEmailRule)->evaluate($ctx));
    }

    // E02: No contact data

    public function test_e02_triggers_when_both_email_and_phone_are_null(): void
    {
        $ctx = $this->makeContext(['contact' => ['email' => null, 'phone' => null]]);

        $this->assertTrue((new E02NoContactDataRule)->evaluate($ctx));
    }

    public function test_e02_does_not_trigger_when_email_is_present(): void
    {
        $ctx = $this->makeContext(['contact' => ['email' => 'user@example.com', 'phone' => null]]);

        $this->assertFalse((new E02NoContactDataRule)->evaluate($ctx));
    }

    public function test_e02_does_not_trigger_when_phone_is_present(): void
    {
        $ctx = $this->makeContext(['contact' => ['email' => null, 'phone' => '+1234567890']]);

        $this->assertFalse((new E02NoContactDataRule)->evaluate($ctx));
    }

    // I01: High-risk IP

    public function test_i01_triggers_for_high_risk_ip(): void
    {
        $ipResult = new IpReputationResult('45.33.32.156', 85, false, false, 'DE', false);
        $ctx = $this->makeContext(['ipReputation' => $ipResult]);

        $this->assertTrue((new I01HighRiskIpRule)->evaluate($ctx));
    }

    public function test_i01_does_not_trigger_for_low_risk_ip(): void
    {
        $ipResult = new IpReputationResult('45.33.32.156', 20, false, false, 'US', false);
        $ctx = $this->makeContext(['ipReputation' => $ipResult]);

        $this->assertFalse((new I01HighRiskIpRule)->evaluate($ctx));
    }

    public function test_i01_does_not_trigger_when_no_ip_data(): void
    {
        $ctx = $this->makeContext(['ipReputation' => null]);

        $this->assertFalse((new I01HighRiskIpRule)->evaluate($ctx));
    }

    // I02: Tor/VPN

    public function test_i02_triggers_for_tor_exit_node(): void
    {
        $ipResult = new IpReputationResult('45.33.32.156', 0, true, false, 'DE', false);
        $ctx = $this->makeContext(['ipReputation' => $ipResult]);

        $this->assertTrue((new I02TorVpnIpRule)->evaluate($ctx));
    }

    public function test_i02_triggers_for_vpn(): void
    {
        $ipResult = new IpReputationResult('45.33.32.156', 0, false, true, 'US', false);
        $ctx = $this->makeContext(['ipReputation' => $ipResult]);

        $this->assertTrue((new I02TorVpnIpRule)->evaluate($ctx));
    }

    public function test_i02_does_not_trigger_for_clean_ip(): void
    {
        $ipResult = new IpReputationResult('45.33.32.156', 0, false, false, 'US', false);
        $ctx = $this->makeContext(['ipReputation' => $ipResult]);

        $this->assertFalse((new I02TorVpnIpRule)->evaluate($ctx));
    }

    public function test_i02_does_not_trigger_when_no_ip_data(): void
    {
        $ctx = $this->makeContext(['ipReputation' => null]);

        $this->assertFalse((new I02TorVpnIpRule)->evaluate($ctx));
    }

    // S01: Burst activity

    public function test_s01_triggers_when_signal_count_exceeds_threshold(): void
    {
        $ctx = $this->makeContext(['signalCount' => 51]);

        $this->assertTrue((new S01BurstActivityRule)->evaluate($ctx));
    }

    public function test_s01_does_not_trigger_at_exact_threshold(): void
    {
        $ctx = $this->makeContext(['signalCount' => 50]);

        $this->assertFalse((new S01BurstActivityRule)->evaluate($ctx));
    }

    public function test_s01_does_not_trigger_below_threshold(): void
    {
        $ctx = $this->makeContext(['signalCount' => 10]);

        $this->assertFalse((new S01BurstActivityRule)->evaluate($ctx));
    }

    // S02: No verified channel

    public function test_s02_triggers_when_no_verified_channel(): void
    {
        $ctx = $this->makeContext(['hasVerifiedChannel' => false]);

        $this->assertTrue((new S02NoVerifiedChannelRule)->evaluate($ctx));
    }

    public function test_s02_does_not_trigger_when_verified_channel_exists(): void
    {
        $ctx = $this->makeContext(['hasVerifiedChannel' => true]);

        $this->assertFalse((new S02NoVerifiedChannelRule)->evaluate($ctx));
    }

    // Rule metadata

    public function test_all_rules_have_correct_metadata(): void
    {
        $rules = [
            [new E01DisposableEmailRule, 'E01', 20],
            [new E02NoContactDataRule, 'E02', 10],
            [new I01HighRiskIpRule, 'I01', 20],
            [new I02TorVpnIpRule, 'I02', 20],
            [new S01BurstActivityRule, 'S01', 10],
            [new S02NoVerifiedChannelRule, 'S02', 10],
        ];

        foreach ($rules as [$rule, $name, $weight]) {
            $this->assertEquals($name, $rule->name());
            $this->assertEquals($weight, $rule->weight());
            $this->assertNotEmpty($rule->label());
        }
    }
}
