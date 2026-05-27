<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\Services\RunSecretVault;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RunSecretVaultTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('secret_proxy.key', base64_encode(str_repeat("\x02", 32)));
        config()->set('secret_proxy.redis_connection', 'secret_proxy');
    }

    private function fakeRedis(array &$store): void
    {
        $conn = Mockery::mock(Connection::class);
        $conn->shouldReceive('setex')->andReturnUsing(function ($key, $ttl, $value) use (&$store) {
            $store[$key] = $value;

            return true;
        });
        $conn->shouldReceive('get')->andReturnUsing(function ($key) use (&$store) {
            return $store[$key] ?? null;
        });
        $conn->shouldReceive('del')->andReturnUsing(function ($key) use (&$store) {
            unset($store[$key]);

            return 1;
        });
        Redis::shouldReceive('connection')->with('secret_proxy')->andReturn($conn);
    }

    public function test_issue_then_resolve_roundtrip(): void
    {
        $store = [];
        $this->fakeRedis($store);

        $vault = new RunSecretVault;
        $bundle = [
            'anthropic_oauth' => 'sk-oauth-real',
            'mcp' => ['gh' => ['url' => 'https://api.gh.com/mcp', 'auth' => 'Bearer ghp_real']],
            'allowed_hosts' => ['api.anthropic.com', 'api.gh.com'],
        ];

        $token = $vault->issue($bundle, 300);

        $this->assertStringContainsString('.', $token);
        $this->assertCount(1, $store, 'vault entry should be stored');

        $resolved = $vault->resolve($token);
        $this->assertNotNull($resolved);
        $this->assertSame('sk-oauth-real', $resolved['anthropic_oauth']);
        $this->assertSame('https://api.gh.com/mcp', $resolved['mcp']['gh']['url']);
        $this->assertSame('Bearer ghp_real', $resolved['mcp']['gh']['auth']);
    }

    public function test_stored_blob_does_not_contain_plaintext_secret(): void
    {
        $store = [];
        $this->fakeRedis($store);

        (new RunSecretVault)->issue([
            'anthropic_oauth' => 'sk-oauth-real',
            'mcp' => [],
            'allowed_hosts' => [],
        ], 300);

        $blob = implode('|', $store);
        $this->assertStringNotContainsString('sk-oauth-real', $blob, 'secret must be encrypted at rest');
    }

    public function test_resolve_rejects_tampered_signature(): void
    {
        $store = [];
        $this->fakeRedis($store);

        $vault = new RunSecretVault;
        $token = $vault->issue(['anthropic_oauth' => 'x', 'mcp' => [], 'allowed_hosts' => []], 300);

        // Flip the first MAC byte deterministically. (Flipping the last base64url
        // char is unreliable — it can land on ignored padding bits, leaving the
        // decoded MAC unchanged.)
        [$id, $mac] = explode('.', $token, 2);
        $macRaw = base64_decode(strtr($mac, '-_', '+/').str_repeat('=', (4 - strlen($mac) % 4) % 4), true);
        $macRaw[0] = chr(ord($macRaw[0]) ^ 0xFF);
        $tampered = $id.'.'.rtrim(strtr(base64_encode($macRaw), '+/', '-_'), '=');

        $this->assertNull($vault->resolve($tampered));
    }

    public function test_revoke_makes_token_unresolvable(): void
    {
        $store = [];
        $this->fakeRedis($store);

        $vault = new RunSecretVault;
        $token = $vault->issue(['anthropic_oauth' => 'x', 'mcp' => [], 'allowed_hosts' => []], 300);

        $vault->revoke($token);
        $this->assertNull($vault->resolve($token));
    }

    public function test_invalid_key_throws(): void
    {
        config()->set('secret_proxy.key', base64_encode('too-short'));

        $this->expectException(RuntimeException::class);
        (new RunSecretVault)->issue(['anthropic_oauth' => null, 'mcp' => [], 'allowed_hosts' => []], 60);
    }

    public function test_key_reusing_app_key_is_rejected(): void
    {
        $shared = str_repeat("\x02", 32);
        config()->set('app.key', 'base64:'.base64_encode($shared));
        config()->set('secret_proxy.key', base64_encode($shared));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not reuse APP_KEY');
        (new RunSecretVault)->issue(['anthropic_oauth' => null, 'mcp' => [], 'allowed_hosts' => []], 60);
    }
}
