<?php

namespace Tests\Feature\Infrastructure\AI;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Shared\Models\Team;
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
}
