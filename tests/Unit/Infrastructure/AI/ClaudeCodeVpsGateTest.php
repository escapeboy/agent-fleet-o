<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Exceptions\VpsLocalAgentException;
use App\Infrastructure\AI\Services\ClaudeCodeVpsGate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClaudeCodeVpsGateTest extends TestCase
{
    use RefreshDatabase;

    private ClaudeCodeVpsGate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gate = new ClaudeCodeVpsGate;
        config(['local_agents.vps.oauth_token' => 'sk-ant-oat-test']);
    }

    public function test_is_configured_returns_false_when_token_missing(): void
    {
        config(['local_agents.vps.oauth_token' => null]);
        $this->assertFalse($this->gate->isConfigured());
    }

    public function test_is_configured_returns_true_when_token_set(): void
    {
        $this->assertTrue($this->gate->isConfigured());
    }

    public function test_is_configured_ignores_global_local_agents_flag(): void
    {
        // Cloud edition forces local_agents.enabled = false as a global safety
        // net against the generic shell-execution local-agent path. The VPS
        // path must work independently because it has its own gates.
        config(['local_agents.enabled' => false]);
        $this->assertTrue($this->gate->isConfigured());
    }

    public function test_super_admin_is_always_allowed(): void
    {
        $user = User::factory()->create(['is_super_admin' => true]);
        $team = $this->createTeam(false);

        $this->assertTrue($this->gate->isAllowedForUser($user, $team));
        $this->assertTrue($this->gate->isAllowedForUser($user, null));
    }

    public function test_non_super_admin_on_denied_team_is_denied(): void
    {
        $user = User::factory()->create(['is_super_admin' => false]);
        $team = $this->createTeam(false);

        $this->assertFalse($this->gate->isAllowedForUser($user, $team));
    }

    public function test_non_super_admin_on_allowed_team_is_allowed(): void
    {
        $user = User::factory()->create(['is_super_admin' => false]);
        $team = $this->createTeam(true);

        $this->assertTrue($this->gate->isAllowedForUser($user, $team));
    }

    public function test_non_super_admin_without_team_is_denied(): void
    {
        $user = User::factory()->create(['is_super_admin' => false]);

        $this->assertFalse($this->gate->isAllowedForUser($user, null));
    }

    public function test_null_user_on_whitelisted_team_is_allowed(): void
    {
        // Queue jobs and automated pipeline calls have no HTTP session / user.
        // A whitelisted team must still be allowed to use VPS Claude Code.
        $team = $this->createTeam(true);
        $this->assertTrue($this->gate->isAllowedForUser(null, $team));
    }

    public function test_null_user_on_non_whitelisted_team_is_denied(): void
    {
        $team = $this->createTeam(false);
        $this->assertFalse($this->gate->isAllowedForUser(null, $team));
    }

    public function test_null_user_without_team_is_denied(): void
    {
        $this->assertFalse($this->gate->isAllowedForUser(null, null));
    }

    public function test_global_flag_does_not_block_super_admin(): void
    {
        // Cloud forces local_agents.enabled = false but the VPS path ignores
        // that flag — super admin must still get access.
        config(['local_agents.enabled' => false]);
        $user = User::factory()->create(['is_super_admin' => true]);
        $team = $this->createTeam(true);

        $this->assertTrue($this->gate->isAllowedForUser($user, $team));
    }

    public function test_assert_allowed_throws_not_configured_when_token_missing(): void
    {
        config(['local_agents.vps.oauth_token' => null]);
        $user = User::factory()->create(['is_super_admin' => true]);

        $this->expectException(VpsLocalAgentException::class);
        $this->expectExceptionMessage('not configured');

        $this->gate->assertAllowed($user, null);
    }

    public function test_assert_allowed_throws_not_allowed_for_denied_user(): void
    {
        $user = User::factory()->create(['is_super_admin' => false]);
        $team = $this->createTeam(false);

        $this->expectException(VpsLocalAgentException::class);
        $this->expectExceptionMessage('not available');

        $this->gate->assertAllowed($user, $team);
    }

    public function test_assert_allowed_passes_for_super_admin(): void
    {
        $user = User::factory()->create(['is_super_admin' => true]);
        $team = $this->createTeam(false);

        $this->gate->assertAllowed($user, $team);
        $this->addToAssertionCount(1);
    }

    private function createTeam(bool $allowed): Team
    {
        $user = User::factory()->create();

        return Team::create([
            'name' => 'Test team '.bin2hex(random_bytes(3)),
            'slug' => 'test-'.bin2hex(random_bytes(3)),
            'owner_id' => $user->id,
            'claude_code_vps_allowed' => $allowed,
        ]);
    }
}
