<?php

namespace Tests\Unit\Domain\Agent;

use App\Domain\Agent\Actions\ResolveTierConfigAction;
use App\Domain\Agent\Enums\ExecutionTier;
use App\Domain\Agent\Models\Agent;
use PHPUnit\Framework\TestCase;

class ResolveTierConfigActionTest extends TestCase
{
    private ResolveTierConfigAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new ResolveTierConfigAction();
    }

    private function makeAgent(array $config): Agent
    {
        $agent = new Agent();
        $agent->config = $config;

        return $agent;
    }

    public function test_returns_standard_defaults_when_no_config(): void
    {
        $agent = $this->makeAgent([]);
        $result = $this->action->execute($agent);

        $this->assertSame(ExecutionTier::Standard, $result['tier']);
        $this->assertArrayHasKey('max_tokens', $result);
        $this->assertArrayHasKey('max_steps', $result);
        $this->assertArrayHasKey('temperature', $result);
        $this->assertArrayHasKey('allow_sub_agents', $result);
    }

    public function test_per_agent_max_tokens_overrides_tier_default(): void
    {
        $agent = $this->makeAgent([
            'execution_tier' => 'flash',
            'max_tokens' => 9999,
        ]);

        $result = $this->action->execute($agent);
        $this->assertSame(9999, $result['max_tokens']);
        $this->assertSame(ExecutionTier::Flash, $result['tier']);
    }

    public function test_per_agent_max_steps_overrides_tier_default(): void
    {
        $agent = $this->makeAgent([
            'execution_tier' => 'standard',
            'max_steps' => 42,
        ]);

        $result = $this->action->execute($agent);
        $this->assertSame(42, $result['max_steps']);
    }

    public function test_per_agent_temperature_overrides_tier_default(): void
    {
        $agent = $this->makeAgent([
            'execution_tier' => 'pro',
            'temperature' => 0.9,
        ]);

        $result = $this->action->execute($agent);
        $this->assertSame(0.9, $result['temperature']);
    }

    public function test_tier_defaults_used_when_no_overrides(): void
    {
        $agent = $this->makeAgent(['execution_tier' => 'ultra']);
        $result = $this->action->execute($agent);

        $ultraDefaults = ExecutionTier::Ultra->config();
        $this->assertSame($ultraDefaults['max_tokens'], $result['max_tokens']);
        $this->assertSame($ultraDefaults['max_steps'], $result['max_steps']);
        $this->assertSame(ExecutionTier::Ultra, $result['tier']);
    }

    public function test_tier_is_correctly_resolved_from_config(): void
    {
        foreach (ExecutionTier::cases() as $tier) {
            $agent = $this->makeAgent(['execution_tier' => $tier->value]);
            $result = $this->action->execute($agent);
            $this->assertSame($tier, $result['tier']);
        }
    }
}
