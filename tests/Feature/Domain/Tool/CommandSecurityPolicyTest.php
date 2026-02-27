<?php

namespace Tests\Feature\Domain\Tool;

use App\Domain\Tool\Services\CommandSecurityPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommandSecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    private CommandSecurityPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new CommandSecurityPolicy;
    }

    public function test_always_blocks_rm_rf(): void
    {
        $result = $this->policy->validate('rm -rf /', '/tmp', [], []);

        $this->assertFalse($result->allowed);
        $this->assertSame('platform', $result->level);
    }

    public function test_always_blocks_dangerous_patterns(): void
    {
        $result = $this->policy->validate('cat file.txt | bash', '/tmp', [], []);

        $this->assertFalse($result->allowed);
        $this->assertSame('platform', $result->level);
    }

    public function test_allows_safe_command_with_no_policy(): void
    {
        $result = $this->policy->validate('ls -la', '/tmp', [], []);

        $this->assertTrue($result->allowed);
    }

    public function test_org_policy_blocked_command(): void
    {
        $orgPolicy = ['blocked_commands' => ['docker']];

        $result = $this->policy->validate('docker ps', '/tmp', [], [], null, null, $orgPolicy);

        $this->assertFalse($result->allowed);
        $this->assertSame('organization', $result->level);
    }

    public function test_org_policy_allowed_commands_whitelist(): void
    {
        $orgPolicy = ['allowed_commands' => ['curl', 'jq']];

        $allowed = $this->policy->validate('curl https://example.com', '/tmp', [], [], null, null, $orgPolicy);
        $blocked = $this->policy->validate('wget https://example.com', '/tmp', [], [], null, null, $orgPolicy);

        $this->assertTrue($allowed->allowed);
        $this->assertFalse($blocked->allowed);
        $this->assertSame('organization', $blocked->level);
    }

    public function test_project_policy_blocked_command(): void
    {
        $projectPolicy = ['blocked_commands' => ['npm']];

        $result = $this->policy->validate('npm install', '/tmp', [], [], $projectPolicy);

        $this->assertFalse($result->allowed);
        $this->assertSame('project', $result->level);
    }

    public function test_agent_policy_blocked_command(): void
    {
        $agentPolicy = ['blocked_commands' => ['pip']];

        $result = $this->policy->validate('pip install requests', '/tmp', [], [], null, $agentPolicy);

        $this->assertFalse($result->allowed);
        $this->assertSame('agent', $result->level);
    }

    public function test_platform_block_takes_precedence_over_org_whitelist(): void
    {
        // Even if org allows everything, platform always-blocked commands are rejected
        $orgPolicy = ['allowed_commands' => ['dd']];

        $result = $this->policy->validate('dd if=/dev/zero of=/dev/sda', '/tmp', [], [], null, null, $orgPolicy);

        $this->assertFalse($result->allowed);
        $this->assertSame('platform', $result->level);
    }

    public function test_tool_allowlist_enforced(): void
    {
        $allowedCommands = ['curl', 'jq'];

        $allowed = $this->policy->validate('curl https://example.com', '/tmp', $allowedCommands, []);
        $blocked = $this->policy->validate('python3 script.py', '/tmp', $allowedCommands, []);

        $this->assertTrue($allowed->allowed);
        $this->assertFalse($blocked->allowed);
        $this->assertSame('tool', $blocked->level);
    }
}
