<?php

namespace Tests\Unit\Domain\Tool;

use App\Domain\Tool\Services\CommandSecurityPolicy;
use PHPUnit\Framework\TestCase;

class OrgSecurityPolicyTest extends TestCase
{
    private CommandSecurityPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new CommandSecurityPolicy;
    }

    public function test_org_blocks_command_from_blocklist(): void
    {
        $result = $this->policy->validate(
            'docker run hello-world', null, [], [],
            null, null,
            ['blocked_commands' => ['docker', 'kubectl']],
        );

        $this->assertFalse($result->allowed);
        $this->assertEquals('organization', $result->level);
        $this->assertStringContainsString('docker', $result->reason);
    }

    public function test_org_blocks_pattern(): void
    {
        $result = $this->policy->validate(
            'run --privileged container', null, [], [],
            null, null,
            ['blocked_patterns' => ['--privileged']],
        );

        $this->assertFalse($result->allowed);
        $this->assertEquals('organization', $result->level);
    }

    public function test_org_allowlist_blocks_unlisted_command(): void
    {
        $result = $this->policy->validate(
            'wget http://example.com', null, [], [],
            null, null,
            ['allowed_commands' => ['curl', 'jq', 'python3']],
        );

        $this->assertFalse($result->allowed);
        $this->assertEquals('organization', $result->level);
        $this->assertStringContainsString('not in organization allowlist', $result->reason);
    }

    public function test_org_allowlist_permits_listed_command(): void
    {
        $result = $this->policy->validate(
            'curl http://example.com', null, [], [],
            null, null,
            ['allowed_commands' => ['curl', 'jq', 'python3']],
        );

        $this->assertTrue($result->allowed);
    }

    public function test_org_blocked_commands_take_precedence_over_tool_allowlist(): void
    {
        $result = $this->policy->validate(
            'docker ps', null, ['docker'], [],
            null, null,
            ['blocked_commands' => ['docker']],
        );

        $this->assertFalse($result->allowed);
        $this->assertEquals('organization', $result->level);
    }

    public function test_org_require_approval_for_patterns(): void
    {
        $result = $this->policy->validate(
            'pip install requests', null, [], [],
            null, null,
            ['require_approval_for' => ['pip install', 'npm install']],
        );

        $this->assertTrue($result->allowed);
        $this->assertTrue($result->requiresApproval);
    }

    public function test_org_no_policy_passes_through(): void
    {
        $result = $this->policy->validate(
            'curl http://example.com', null, [], [],
            null, null,
            null,
        );

        $this->assertTrue($result->allowed);
    }

    public function test_org_empty_policy_passes_through(): void
    {
        $result = $this->policy->validate(
            'curl http://example.com', null, [], [],
            null, null,
            [],
        );

        $this->assertTrue($result->allowed);
    }

    public function test_platform_blocks_still_take_priority(): void
    {
        $result = $this->policy->validate(
            'rm -rf /', null, [], [],
            null, null,
            ['allowed_commands' => ['rm']],
        );

        $this->assertFalse($result->allowed);
        $this->assertEquals('platform', $result->level);
    }

    public function test_org_policy_evaluated_before_project_and_agent(): void
    {
        // Org blocks docker, project allows it — org should win
        $result = $this->policy->validate(
            'docker build .', null, ['docker'], [],
            null, null,
            ['blocked_commands' => ['docker']],
        );

        $this->assertFalse($result->allowed);
        $this->assertEquals('organization', $result->level);
    }
}
