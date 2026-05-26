<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\EgressAllowlist;
use App\Infrastructure\AI\Services\RunSecretVault;
use App\Infrastructure\AI\Services\SecretProxyInjector;
use Mockery;
use Tests\TestCase;

class SecretProxyInjectorTest extends TestCase
{
    private string $workdir;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('secret_proxy.enabled', true);
        config()->set('secret_proxy.base_url', 'http://agent-fleet-secret-proxy:8099');
        config()->set('secret_proxy.key', base64_encode(str_repeat("\x03", 32)));
        config()->set('secret_proxy.vault_ttl_margin', 120);

        $this->workdir = sys_get_temp_dir().'/sp-injector-'.bin2hex(random_bytes(6));
        mkdir($this->workdir, 0700, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->workdir.'/.claude.json');
        @rmdir($this->workdir);
        parent::tearDown();
    }

    private function request(): AiRequestDTO
    {
        // teamId null → EgressAllowlist skips the DB lookup (pure unit test).
        return new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            systemPrompt: '',
            userPrompt: 'hello',
            teamId: null,
        );
    }

    public function test_enabled_requires_full_configuration(): void
    {
        $this->assertTrue(SecretProxyInjector::enabled());

        config()->set('secret_proxy.base_url', '');
        $this->assertFalse(SecretProxyInjector::enabled());
    }

    public function test_apply_swaps_oauth_and_mcp_secrets_for_opaque_token(): void
    {
        $captured = null;
        $vault = Mockery::mock(RunSecretVault::class);
        $vault->shouldReceive('issue')->once()->andReturnUsing(function (array $bundle) use (&$captured) {
            $captured = $bundle;

            return 'AAAAAAAAAAAAAAAAAAAAAA.OPAQUEMAC';
        });

        $injector = new SecretProxyInjector($vault, new EgressAllowlist);

        $env = ['HOME' => $this->workdir, 'CLAUDE_CODE_OAUTH_TOKEN' => 'OAUTH-REAL'];
        $realMcp = ['mcpServers' => [
            'gh' => ['type' => 'http', 'url' => 'https://api.gh.com/mcp', 'headers' => ['Authorization' => 'Bearer SANCTUM-REAL']],
        ]];

        $token = $injector->apply($this->request(), $this->workdir, $env, 'OAUTH-REAL', 300, $realMcp);

        // Env now carries the opaque token + proxy base, not the real OAuth token.
        $this->assertSame($token, $env['CLAUDE_CODE_OAUTH_TOKEN']);
        $this->assertNotSame('OAUTH-REAL', $env['CLAUDE_CODE_OAUTH_TOKEN']);
        $this->assertSame('http://agent-fleet-secret-proxy:8099/egress/anthropic', $env['ANTHROPIC_BASE_URL']);

        // .claude.json points at the proxy and carries only the opaque token.
        $written = file_get_contents($this->workdir.'/.claude.json');
        $this->assertStringNotContainsString('SANCTUM-REAL', $written, 'real MCP bearer must not be on disk');
        $this->assertStringNotContainsString('OAUTH-REAL', $written);

        $json = json_decode($written, true);
        $this->assertSame('http://agent-fleet-secret-proxy:8099/egress/mcp/gh', $json['mcpServers']['gh']['url']);
        $this->assertSame('Bearer '.$token, $json['mcpServers']['gh']['headers']['Authorization']);

        // The vault bundle keeps the real secrets + a default-deny allowlist.
        $this->assertSame('OAUTH-REAL', $captured['anthropic_oauth']);
        $this->assertSame('https://api.gh.com/mcp', $captured['mcp']['gh']['url']);
        $this->assertSame('Bearer SANCTUM-REAL', $captured['mcp']['gh']['auth']);
        $this->assertContains('api.anthropic.com', $captured['allowed_hosts']);
        $this->assertContains('api.gh.com', $captured['allowed_hosts']);
    }

    public function test_unparseable_mcp_host_is_proxied_without_bundle_entry(): void
    {
        $captured = null;
        $vault = Mockery::mock(RunSecretVault::class);
        $vault->shouldReceive('issue')->andReturnUsing(function (array $bundle) use (&$captured) {
            $captured = $bundle;

            return 'TID.MAC';
        });

        $injector = new SecretProxyInjector($vault, new EgressAllowlist);

        $env = ['CLAUDE_CODE_OAUTH_TOKEN' => 'OAUTH-REAL'];
        // hostless url → parse_url(PHP_URL_HOST) is null
        $realMcp = ['mcpServers' => [
            'weird' => ['type' => 'http', 'url' => '/relative/no-host', 'headers' => ['Authorization' => 'Bearer LEAK']],
        ]];

        $injector->apply($this->request(), $this->workdir, $env, 'OAUTH-REAL', 300, $realMcp);

        $written = file_get_contents($this->workdir.'/.claude.json');
        // Real secret stripped even though the host couldn't be parsed (fail closed).
        $this->assertStringNotContainsString('LEAK', $written);
        // No bundle entry → daemon will 404 the ref.
        $this->assertArrayNotHasKey('weird', $captured['mcp']);
    }

    public function test_stdio_servers_pass_through_unchanged(): void
    {
        $vault = Mockery::mock(RunSecretVault::class);
        $vault->shouldReceive('issue')->andReturn('TID.MAC');

        $injector = new SecretProxyInjector($vault, new EgressAllowlist);

        $env = ['CLAUDE_CODE_OAUTH_TOKEN' => 'OAUTH-REAL'];
        $realMcp = ['mcpServers' => [
            'local' => ['command' => 'node', 'args' => ['server.js'], 'env' => ['API_KEY' => 'stdio-real']],
        ]];

        $injector->apply($this->request(), $this->workdir, $env, 'OAUTH-REAL', 300, $realMcp);

        // Documented Phase-1 limitation: stdio env is not proxied.
        $json = json_decode(file_get_contents($this->workdir.'/.claude.json'), true);
        $this->assertSame('node', $json['mcpServers']['local']['command']);
        $this->assertSame('stdio-real', $json['mcpServers']['local']['env']['API_KEY']);
    }
}
