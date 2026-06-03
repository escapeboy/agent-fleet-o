<?php

namespace Tests\Feature\Domain\Agent;

use App\Domain\Agent\Enums\ToolLockoutMatchMode;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentHook;
use App\Domain\Agent\Models\AgentToolLockout;
use App\Domain\Agent\Services\ToolCallGovernor;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolCallGovernorTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent.tool_governance.enabled' => true]);
        $this->team = Team::factory()->create();
        $this->agent = Agent::factory()->create(['team_id' => $this->team->id]);
    }

    private function governor(): ToolCallGovernor
    {
        return app(ToolCallGovernor::class);
    }

    private function lock(string $resource, ToolLockoutMatchMode $mode = ToolLockoutMatchMode::Equals, ?string $agentId = null, ?string $reason = 'locked for review'): AgentToolLockout
    {
        return AgentToolLockout::create([
            'team_id' => $this->team->id,
            'agent_id' => $agentId,
            'resource' => $resource,
            'match_mode' => $mode,
            'reason' => $reason,
        ]);
    }

    public function test_no_op_when_flag_disabled(): void
    {
        config(['agent.tool_governance.enabled' => false]);
        $this->lock('src/auth.php');

        $this->assertNull($this->governor()->assert($this->agent, 'file_write', ['path' => 'src/auth.php', 'content' => 'x']));
    }

    public function test_allows_when_no_rules(): void
    {
        $this->assertNull($this->governor()->assert($this->agent, 'file_write', ['path' => 'src/clean.php', 'content' => 'x']));
    }

    public function test_lockout_equals_blocks_matching_path(): void
    {
        $this->lock('src/auth.php', reason: 'Auth needs a second reviewer.');

        $reason = $this->governor()->assert($this->agent, 'file_write', ['path' => 'src/auth.php', 'content' => 'x']);
        $this->assertSame('Auth needs a second reviewer.', $reason);

        // A different path is not blocked.
        $this->assertNull($this->governor()->assert($this->agent, 'file_write', ['path' => 'src/other.php', 'content' => 'x']));
    }

    public function test_lockout_prefix_and_contains_modes(): void
    {
        $this->lock('src/secure/', ToolLockoutMatchMode::Prefix);
        $this->assertNotNull($this->governor()->assert($this->agent, 'file_write', ['path' => 'src/secure/keys.php', 'content' => 'x']));

        $other = Agent::factory()->create(['team_id' => $this->team->id]);
        AgentToolLockout::create([
            'team_id' => $this->team->id, 'agent_id' => $other->id,
            'resource' => 'rm -rf', 'match_mode' => ToolLockoutMatchMode::Contains, 'reason' => 'no',
        ]);
        $this->assertNotNull($this->governor()->assert($other, 'bash_execute', ['command' => 'sudo rm -rf /tmp']));
    }

    public function test_released_lockout_does_not_block(): void
    {
        $lock = $this->lock('src/auth.php');
        $lock->update(['released_at' => now()]);

        $this->assertNull($this->governor()->assert($this->agent, 'file_write', ['path' => 'src/auth.php', 'content' => 'x']));
    }

    public function test_team_wide_lockout_applies_to_any_agent(): void
    {
        $this->lock('deploy.sh', reason: 'Frozen during incident.'); // agent_id null = team-wide
        $other = Agent::factory()->create(['team_id' => $this->team->id]);

        $this->assertNotNull($this->governor()->assert($other, 'bash_execute', ['command' => 'deploy.sh']));
    }

    public function test_agent_specific_lockout_does_not_leak_to_other_agent(): void
    {
        $other = Agent::factory()->create(['team_id' => $this->team->id]);
        $this->lock('src/auth.php', agentId: $this->agent->id);

        // Locked for $this->agent, but $other is free.
        $this->assertNotNull($this->governor()->assert($this->agent, 'file_write', ['path' => 'src/auth.php', 'content' => 'x']));
        $this->assertNull($this->governor()->assert($other, 'file_write', ['path' => 'src/auth.php', 'content' => 'x']));
    }

    public function test_lockout_is_tenant_isolated(): void
    {
        $teamB = Team::factory()->create();
        $agentB = Agent::factory()->create(['team_id' => $teamB->id]);
        $this->lock('src/auth.php'); // team A

        // Team B's agent hitting the same path is not affected by team A's lockout.
        $this->assertNull($this->governor()->assert($agentB, 'file_write', ['path' => 'src/auth.php', 'content' => 'x']));
    }

    public function test_on_tool_call_guardrail_blocks_dangerous_command(): void
    {
        AgentHook::create([
            'team_id' => $this->team->id,
            'agent_id' => null,
            'name' => 'Block rm -rf',
            'position' => 'on_tool_call',
            'type' => 'guardrail',
            'config' => ['rules' => [[
                'field' => 'tool_input',
                'operator' => 'contains',
                'value' => 'rm -rf',
                'message' => 'Destructive command blocked by guardrail.',
            ]]],
            'priority' => 0,
            'enabled' => true,
        ]);

        $reason = $this->governor()->assert($this->agent, 'bash_execute', ['command' => 'rm -rf /tmp/x']);
        $this->assertSame('Destructive command blocked by guardrail.', $reason);

        // A safe command passes.
        $this->assertNull($this->governor()->assert($this->agent, 'bash_execute', ['command' => 'ls -la']));
    }
}
