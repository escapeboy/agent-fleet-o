<?php

namespace Tests\Feature\Domain\Tool;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentToolLockout;
use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Services\ToolTranslator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Proves the ToolTranslator wiring: the governanceDenial() helper that every
 * mutating built-in closure calls returns the agent-facing "Error: blocked by
 * governance" string on deny, and is a no-op without an agent / when disabled.
 */
class ToolTranslatorGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private function denial(?Agent $agent, string $tool, array $args): ?string
    {
        $m = new ReflectionMethod(ToolTranslator::class, 'governanceDenial');
        $m->setAccessible(true);

        return $m->invoke(app(ToolTranslator::class), $agent, $tool, $args);
    }

    public function test_null_agent_is_no_op(): void
    {
        config(['agent.tool_governance.enabled' => true]);
        $this->assertNull($this->denial(null, 'file_write', ['path' => 'x']));
    }

    public function test_disabled_governance_is_no_op(): void
    {
        config(['agent.tool_governance.enabled' => false]);
        $team = Team::factory()->create();
        $agent = Agent::factory()->create(['team_id' => $team->id]);
        AgentToolLockout::factory()->create(['team_id' => $team->id, 'resource' => 'src/auth.php']);

        $this->assertNull($this->denial($agent, 'file_write', ['path' => 'src/auth.php']));
    }

    public function test_blocked_call_returns_governance_error_string(): void
    {
        config(['agent.tool_governance.enabled' => true]);
        $team = Team::factory()->create();
        $agent = Agent::factory()->create(['team_id' => $team->id]);
        AgentToolLockout::factory()->create([
            'team_id' => $team->id,
            'resource' => 'src/auth.php',
            'reason' => 'Locked pending review.',
        ]);

        $denial = $this->denial($agent, 'file_write', ['path' => 'src/auth.php', 'content' => 'x']);

        $this->assertSame('Error: blocked by governance — Locked pending review.', $denial);
    }

    public function test_allowed_call_returns_null(): void
    {
        config(['agent.tool_governance.enabled' => true]);
        $team = Team::factory()->create();
        $agent = Agent::factory()->create(['team_id' => $team->id]);

        $this->assertNull($this->denial($agent, 'file_write', ['path' => 'src/clean.php', 'content' => 'x']));
    }
}
