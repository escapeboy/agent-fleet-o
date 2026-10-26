<?php

namespace Tests\Feature\Infrastructure\AI;

use App\Domain\Agent\Models\Agent;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Exceptions\VpsLocalAgentException;
use App\Infrastructure\AI\Gateways\LocalAgentGateway;
use App\Infrastructure\AI\Services\ClaudeCodeVpsConcurrencyCap;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class LocalAgentGatewayVpsTest extends TestCase
{
    use RefreshDatabase;

    private string $shimDir;

    private string $shimPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shimDir = sys_get_temp_dir().'/claude-shim-'.bin2hex(random_bytes(4));
        @mkdir($this->shimDir, 0755, true);
        $this->shimPath = $this->shimDir.'/claude';

        config(['local_agents.enabled' => true]);
        config(['local_agents.vps.oauth_token' => 'sk-ant-oat-test']);
        config(['local_agents.vps.binary_path' => $this->shimPath]);
        config(['local_agents.vps.max_concurrency_per_team' => 2]);
        config(['local_agents.vps.timeout_seconds' => 30]);
        config(['llm_providers.claude-code-vps' => [
            'name' => 'Claude Code (VPS)',
            'local' => true,
            'vps' => true,
            'agent_key' => 'claude-code-vps',
            'models' => [
                'claude-sonnet-4-5' => ['label' => 'Claude Sonnet 4.5', 'input_cost' => 0, 'output_cost' => 0],
            ],
        ]]);

        Redis::connection('locks')->flushdb();
    }

    protected function tearDown(): void
    {
        Redis::connection('locks')->flushdb();

        if (is_dir($this->shimDir)) {
            foreach (scandir($this->shimDir) as $f) {
                if ($f !== '.' && $f !== '..') {
                    @unlink($this->shimDir.'/'.$f);
                }
            }
            @rmdir($this->shimDir);
        }

        parent::tearDown();
    }

    public function test_super_admin_executes_via_vps_path_and_writes_audit(): void
    {
        $this->writeShim(<<<'SH'
#!/bin/sh
echo '{"type":"assistant","message":{"content":[{"type":"text","text":"Hello from shim"}]}}'
echo "TOKEN=${CLAUDE_CODE_OAUTH_TOKEN}" >&2
exit 0
SH);

        $user = User::factory()->create(['is_super_admin' => true]);
        $team = $this->createTeam(false, $user);

        $gateway = app(LocalAgentGateway::class);
        $response = $gateway->complete(new AiRequestDTO(
            provider: 'claude-code-vps',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'You are helpful.',
            userPrompt: 'Hi',
            userId: $user->id,
            teamId: $team->id,
        ));

        $this->assertStringContainsString('Hello from shim', $response->content);
        $this->assertSame('claude-code-vps', $response->provider);
        $this->assertSame(0, $response->usage->costCredits);

        $audit = AuditEntry::withoutGlobalScopes()
            ->where('event', 'claude_code_vps.invoke')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame($team->id, $audit->team_id);
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame(0, $audit->properties['exit_code']);
    }

    private function runAndGetAudit(string $shim, ?string $purpose = null): AuditEntry
    {
        $this->writeShim($shim);
        $user = User::factory()->create(['is_super_admin' => true]);
        $team = $this->createTeam(false, $user);

        app(LocalAgentGateway::class)->complete(new AiRequestDTO(
            provider: 'claude-code-vps',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'sys',
            userPrompt: 'usr',
            userId: $user->id,
            teamId: $team->id,
            purpose: $purpose,
        ));

        return AuditEntry::withoutGlobalScopes()->where('event', 'claude_code_vps.invoke')->firstOrFail();
    }

    public function test_audit_records_builtin_tool_calls_names_only(): void
    {
        $audit = $this->runAndGetAudit(<<<'SH'
#!/bin/sh
echo '{"type":"assistant","message":{"content":[{"type":"tool_use","name":"Bash","input":{"command":"echo SECRET"}},{"type":"tool_use","name":"mcp__fleetq__x","input":{}}]}}'
echo '{"type":"assistant","message":{"content":[{"type":"tool_use","name":"Read","input":{}}]}}'
SH);

        $this->assertSame(['Bash', 'Read'], $audit->properties['builtin_tool_calls']);
        $this->assertArrayNotHasKey('builtin_tool_calls_truncated', $audit->properties);
        $this->assertStringNotContainsString('SECRET', json_encode($audit->properties));
    }

    public function test_audit_records_bash_programs_without_arguments(): void
    {
        $audit = $this->runAndGetAudit(<<<'SH'
#!/bin/sh
echo '{"type":"assistant","message":{"content":[{"type":"tool_use","name":"Bash","input":{"command":"TOK=FAKE_SECRET /usr/bin/curl -H FAKE_SECRET http://x"}},{"type":"tool_use","name":"Bash","input":{"command":""}},{"type":"tool_use","name":"Read","input":{}}]}}'
SH);

        $this->assertSame(['curl', '?'], $audit->properties['bash_programs']);
        $this->assertArrayNotHasKey('bash_programs_truncated', $audit->properties);
        $this->assertStringNotContainsString('FAKE_SECRET', json_encode($audit->properties));
    }

    public function test_audit_has_empty_bash_programs_when_no_bash_calls(): void
    {
        $audit = $this->runAndGetAudit("#!/bin/sh\necho '{\"type\":\"assistant\",\"message\":{\"content\":[{\"type\":\"text\",\"text\":\"ok\"}]}}'");

        $this->assertSame([], $audit->properties['bash_programs']);
    }

    public function test_audit_has_empty_builtin_list_when_parsed_without_builtins(): void
    {
        $audit = $this->runAndGetAudit("#!/bin/sh\necho '{\"type\":\"assistant\",\"message\":{\"content\":[{\"type\":\"text\",\"text\":\"ok\"}]}}'");

        $this->assertArrayHasKey('builtin_tool_calls', $audit->properties);
        $this->assertSame([], $audit->properties['builtin_tool_calls']);
    }

    public function test_audit_omits_builtin_key_for_assistant_runs(): void
    {
        $audit = $this->runAndGetAudit(
            "#!/bin/sh\necho '{\"type\":\"assistant\",\"message\":{\"content\":[{\"type\":\"tool_use\",\"name\":\"Bash\",\"input\":{}}]}}'",
            'platform_assistant',
        );

        $this->assertArrayNotHasKey('builtin_tool_calls', $audit->properties);
        $this->assertArrayNotHasKey('bash_programs', $audit->properties);
    }

    public function test_audit_omits_builtin_key_when_stdout_empty(): void
    {
        $this->writeShim("#!/bin/sh\necho boom >&2\nexit 1");
        $user = User::factory()->create(['is_super_admin' => true]);
        $team = $this->createTeam(false, $user);

        try {
            app(LocalAgentGateway::class)->complete(new AiRequestDTO(
                provider: 'claude-code-vps',
                model: 'claude-sonnet-4-5',
                systemPrompt: 'sys',
                userPrompt: 'usr',
                userId: $user->id,
                teamId: $team->id,
            ));
            $this->fail('expected exception');
        } catch (\RuntimeException) {
        }

        $audit = AuditEntry::withoutGlobalScopes()->where('event', 'claude_code_vps.invoke')->firstOrFail();
        $this->assertArrayNotHasKey('builtin_tool_calls', $audit->properties);
    }

    public function test_denied_user_cannot_invoke_vps_path(): void
    {
        $this->writeShim("#!/bin/sh\necho '{\"type\":\"assistant\",\"message\":{\"content\":[{\"type\":\"text\",\"text\":\"x\"}]}}'");

        $user = User::factory()->create(['is_super_admin' => false]);
        $team = $this->createTeam(false, $user);

        $this->expectException(VpsLocalAgentException::class);
        $this->expectExceptionMessage('not available');

        app(LocalAgentGateway::class)->complete(new AiRequestDTO(
            provider: 'claude-code-vps',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'sys',
            userPrompt: 'usr',
            userId: $user->id,
            teamId: $team->id,
        ));
    }

    public function test_concurrency_cap_blocks_third_call(): void
    {
        $this->writeShim("#!/bin/sh\necho '{\"type\":\"assistant\",\"message\":{\"content\":[{\"type\":\"text\",\"text\":\"x\"}]}}'");

        $user = User::factory()->create(['is_super_admin' => true]);
        $team = $this->createTeam(false, $user);

        $cap = app(ClaudeCodeVpsConcurrencyCap::class);
        $cap->acquire($team->id);
        $cap->acquire($team->id);

        $this->expectException(VpsLocalAgentException::class);
        $this->expectExceptionMessage('concurrency cap');

        app(LocalAgentGateway::class)->complete(new AiRequestDTO(
            provider: 'claude-code-vps',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'sys',
            userPrompt: 'usr',
            userId: $user->id,
            teamId: $team->id,
        ));
    }

    public function test_missing_binary_throws(): void
    {
        // Do NOT write a shim this time.
        $user = User::factory()->create(['is_super_admin' => true]);
        $team = $this->createTeam(false, $user);

        $this->expectException(VpsLocalAgentException::class);
        $this->expectExceptionMessage('binary not found');

        app(LocalAgentGateway::class)->complete(new AiRequestDTO(
            provider: 'claude-code-vps',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'sys',
            userPrompt: 'usr',
            userId: $user->id,
            teamId: $team->id,
        ));
    }

    public function test_cap_is_released_after_success_so_next_call_succeeds(): void
    {
        $this->writeShim(<<<'SH'
#!/bin/sh
echo '{"type":"assistant","message":{"content":[{"type":"text","text":"ok"}]}}'
SH);

        $user = User::factory()->create(['is_super_admin' => true]);
        $team = $this->createTeam(false, $user);

        $request = new AiRequestDTO(
            provider: 'claude-code-vps',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'sys',
            userPrompt: 'usr',
            userId: $user->id,
            teamId: $team->id,
        );

        app(LocalAgentGateway::class)->complete($request);
        app(LocalAgentGateway::class)->complete($request);
        app(LocalAgentGateway::class)->complete($request);

        $this->assertSame(
            0,
            app(ClaudeCodeVpsConcurrencyCap::class)->activeCount($team->id),
            'cap must be released after each successful call',
        );
    }

    private function writeShim(string $script): void
    {
        file_put_contents($this->shimPath, $script);
        chmod($this->shimPath, 0755);
    }

    private function createTeam(bool $allowed, User $owner): Team
    {
        return Team::create([
            'name' => 'Test team '.bin2hex(random_bytes(3)),
            'slug' => 'test-'.bin2hex(random_bytes(3)),
            'owner_id' => $owner->id,
            'claude_code_vps_allowed' => $allowed,
        ]);
    }

    /**
     * Shim that copies ${HOME}/.claude.json (if any) into a side-file
     * the test reads back to assert what the gateway wrote.
     */
    private function writeMcpProbeShim(string $sidecarPath): void
    {
        $this->writeShim(<<<SH
#!/bin/sh
echo '{"type":"assistant","message":{"content":[{"type":"text","text":"ok"}]}}'
if [ -f "\$HOME/.claude.json" ]; then
    cat "\$HOME/.claude.json" > {$sidecarPath}
else
    echo "MISSING" > {$sidecarPath}
fi
SH);
    }

    public function test_agent_call_writes_claude_mcp_config_with_attached_http_tool(): void
    {
        $sidecar = $this->shimDir.'/mcp-config-probe.json';
        $this->writeMcpProbeShim($sidecar);

        $user = User::factory()->create(['is_super_admin' => true]);
        $team = $this->createTeam(true, $user);

        $tool = Tool::factory()->create([
            'team_id' => $team->id,
            'slug' => 'fleetq_platform_mcp',
            'type' => ToolType::McpHttp,
            'status' => ToolStatus::Active,
            'transport_config' => [
                'url' => 'http://nginx',
                'headers' => ['Authorization' => 'Bearer test-key'],
            ],
            'credentials' => [],
        ]);

        $agent = Agent::factory()->create([
            'team_id' => $team->id,
            'provider' => 'claude-code-vps',
            'model' => 'claude-sonnet-4-5',
        ]);
        $agent->tools()->attach($tool->id);

        app(LocalAgentGateway::class)->complete(new AiRequestDTO(
            provider: 'claude-code-vps',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'sys',
            userPrompt: 'usr',
            userId: $user->id,
            teamId: $team->id,
            agentId: $agent->id,
        ));

        $this->assertFileExists($sidecar);
        $written = json_decode((string) file_get_contents($sidecar), true);

        $this->assertIsArray($written, 'shim should have copied a JSON file');
        $this->assertArrayHasKey('mcpServers', $written);
        $this->assertArrayHasKey('fleetq_platform_mcp', $written['mcpServers']);
        $this->assertSame('http', $written['mcpServers']['fleetq_platform_mcp']['type']);
        // URL gets `/mcp` appended (same convention McpHttpClient uses cloud-side).
        $this->assertSame('http://nginx/mcp', $written['mcpServers']['fleetq_platform_mcp']['url']);
    }

    public function test_agent_call_with_no_attached_tools_writes_no_config(): void
    {
        $sidecar = $this->shimDir.'/mcp-config-probe.json';
        $this->writeMcpProbeShim($sidecar);

        $user = User::factory()->create(['is_super_admin' => true]);
        $team = $this->createTeam(true, $user);

        $agent = Agent::factory()->create([
            'team_id' => $team->id,
            'provider' => 'claude-code-vps',
            'model' => 'claude-sonnet-4-5',
        ]);

        app(LocalAgentGateway::class)->complete(new AiRequestDTO(
            provider: 'claude-code-vps',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'sys',
            userPrompt: 'usr',
            userId: $user->id,
            teamId: $team->id,
            agentId: $agent->id,
        ));

        $this->assertFileExists($sidecar);
        $this->assertSame('MISSING', trim((string) file_get_contents($sidecar)));
    }

    public function test_request_with_no_agent_id_writes_no_config(): void
    {
        $sidecar = $this->shimDir.'/mcp-config-probe.json';
        $this->writeMcpProbeShim($sidecar);

        $user = User::factory()->create(['is_super_admin' => true]);
        $team = $this->createTeam(true, $user);

        app(LocalAgentGateway::class)->complete(new AiRequestDTO(
            provider: 'claude-code-vps',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'sys',
            userPrompt: 'usr',
            userId: $user->id,
            teamId: $team->id,
            // no agentId
        ));

        $this->assertSame('MISSING', trim((string) file_get_contents($sidecar)));
    }

    public function test_assistant_call_does_not_write_claude_mcp_config(): void
    {
        $sidecar = $this->shimDir.'/mcp-config-probe.json';
        $this->writeMcpProbeShim($sidecar);

        $user = User::factory()->create(['is_super_admin' => true]);
        $team = $this->createTeam(true, $user);

        // Even with an agent that has tools, assistant path should bypass the bridge.
        $tool = Tool::factory()->create([
            'team_id' => $team->id,
            'slug' => 'fleetq_platform_mcp',
            'type' => ToolType::McpHttp,
            'status' => ToolStatus::Active,
            'transport_config' => ['url' => 'http://nginx'],
            'credentials' => [],
        ]);
        $agent = Agent::factory()->create([
            'team_id' => $team->id,
            'provider' => 'claude-code-vps',
            'model' => 'claude-sonnet-4-5',
        ]);
        $agent->tools()->attach($tool->id);

        app(LocalAgentGateway::class)->complete(new AiRequestDTO(
            provider: 'claude-code-vps',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'sys',
            userPrompt: 'usr',
            userId: $user->id,
            teamId: $team->id,
            agentId: $agent->id,
            purpose: 'platform_assistant',
        ));

        $this->assertSame('MISSING', trim((string) file_get_contents($sidecar)));
    }
}
