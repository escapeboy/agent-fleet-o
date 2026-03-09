<?php

namespace Tests\Unit\Domain\Agent;

use App\Domain\Agent\Enums\ExecutionTier;
use PHPUnit\Framework\TestCase;

class ExecutionTierTest extends TestCase
{
    public function test_fromConfig_defaults_to_standard(): void
    {
        $tier = ExecutionTier::fromConfig([]);
        $this->assertSame(ExecutionTier::Standard, $tier);
    }

    public function test_fromConfig_returns_correct_tier(): void
    {
        $this->assertSame(ExecutionTier::Flash, ExecutionTier::fromConfig(['execution_tier' => 'flash']));
        $this->assertSame(ExecutionTier::Standard, ExecutionTier::fromConfig(['execution_tier' => 'standard']));
        $this->assertSame(ExecutionTier::Pro, ExecutionTier::fromConfig(['execution_tier' => 'pro']));
        $this->assertSame(ExecutionTier::Ultra, ExecutionTier::fromConfig(['execution_tier' => 'ultra']));
    }

    public function test_fromConfig_falls_back_to_standard_on_invalid_value(): void
    {
        $tier = ExecutionTier::fromConfig(['execution_tier' => 'invalid_value']);
        $this->assertSame(ExecutionTier::Standard, $tier);
    }

    public function test_config_returns_expected_keys(): void
    {
        $config = ExecutionTier::Standard->config();

        $this->assertArrayHasKey('model_preference', $config);
        $this->assertArrayHasKey('max_tokens', $config);
        $this->assertArrayHasKey('max_steps', $config);
        $this->assertArrayHasKey('temperature', $config);
        $this->assertArrayHasKey('allow_sub_agents', $config);
        $this->assertArrayHasKey('planning_depth', $config);
    }

    public function test_flash_has_lowest_token_budget(): void
    {
        $flash = ExecutionTier::Flash->config();
        $standard = ExecutionTier::Standard->config();
        $pro = ExecutionTier::Pro->config();
        $ultra = ExecutionTier::Ultra->config();

        $this->assertLessThanOrEqual($standard['max_tokens'], $flash['max_tokens']);
        $this->assertLessThanOrEqual($pro['max_tokens'], $standard['max_tokens']);
        $this->assertLessThanOrEqual($ultra['max_tokens'], $pro['max_tokens']);
    }

    public function test_flash_does_not_allow_sub_agents(): void
    {
        $this->assertFalse(ExecutionTier::Flash->config()['allow_sub_agents']);
    }

    public function test_pro_and_ultra_allow_sub_agents(): void
    {
        $this->assertTrue(ExecutionTier::Pro->config()['allow_sub_agents']);
        $this->assertTrue(ExecutionTier::Ultra->config()['allow_sub_agents']);
    }

    public function test_all_tiers_have_non_empty_labels(): void
    {
        foreach (ExecutionTier::cases() as $tier) {
            $this->assertNotEmpty($tier->label());
            $this->assertNotEmpty($tier->shortLabel());
            $this->assertNotEmpty($tier->badgeClass());
        }
    }
}
