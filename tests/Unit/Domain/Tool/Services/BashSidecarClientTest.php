<?php

namespace Tests\Unit\Domain\Tool\Services;

use App\Domain\Tool\Services\BashSidecarClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BashSidecarClientTest extends TestCase
{
    private BashSidecarClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent.bash_sidecar_url' => 'http://bash_sidecar:3001']);
        config(['agent.bash_sidecar_secret' => 'test-secret']);
        $this->client = new BashSidecarClient;
    }

    public function test_create_session_succeeds_on_201(): void
    {
        Http::fake(['*/session' => Http::response(['sessionId' => 'sess-1'], 201)]);

        $this->client->createSession('sess-1');

        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/session')
            && $req->data()['sessionId'] === 'sess-1'
        );
    }

    public function test_create_session_throws_on_connection_failure(): void
    {
        Http::fake(['*/session' => fn () => throw new ConnectionException]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Bash sandbox is unavailable');

        $this->client->createSession('sess-1');
    }

    public function test_create_session_throws_on_429(): void
    {
        Http::fake(['*/session' => Http::response(['error' => 'session_limit_exceeded'], 429)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('session limit exceeded');

        $this->client->createSession('sess-1');
    }

    public function test_create_session_throws_on_non_success_response(): void
    {
        Http::fake(['*/session' => Http::response(['error' => 'internal error'], 500)]);

        $this->expectException(\RuntimeException::class);

        $this->client->createSession('sess-1');
    }

    public function test_run_returns_stdout(): void
    {
        Http::fake(['*/exec' => Http::response(['stdout' => 'hello', 'stderr' => '', 'exitCode' => 0], 200)]);

        $result = $this->client->run('sess-1', 'echo hello', 5_000);

        $this->assertSame('hello', $result['stdout']);
        $this->assertSame('', $result['stderr']);
        $this->assertSame(0, $result['exitCode']);
    }

    public function test_run_uses_http_timeout_exceeding_command_timeout(): void
    {
        Http::fake(['*/exec' => Http::response(['stdout' => '', 'stderr' => '', 'exitCode' => 0], 200)]);

        // 30_000 ms command timeout → expect HTTP timeout of at least 40s
        $this->client->run('sess-1', 'sleep 29', 30_000);

        Http::assertSent(function ($req) {
            // Verify timeoutMs was passed to the sidecar
            return $req->data()['timeoutMs'] === 30_000;
        });
    }

    public function test_run_returns_timeout_result_on_408(): void
    {
        Http::fake(['*/exec' => Http::response(['error' => 'timeout'], 408)]);

        $result = $this->client->run('sess-1', 'sleep 999', 1_000);

        $this->assertSame(124, $result['exitCode']);
        $this->assertSame('Command timed out', $result['stderr']);
    }

    public function test_run_returns_degraded_result_on_connection_failure(): void
    {
        Http::fake(['*/exec' => fn () => throw new ConnectionException]);

        $result = $this->client->run('sess-1', 'echo hi', 5_000);

        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('unavailable', $result['stderr']);
    }

    public function test_run_returns_429_result_as_error(): void
    {
        Http::fake(['*/exec' => Http::response(['error' => 'session_limit_exceeded'], 429)]);

        $result = $this->client->run('sess-1', 'echo hi', 5_000);

        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('session limit', $result['stderr']);
    }

    public function test_destroy_session_sends_delete_request(): void
    {
        Http::fake(['*/session/*' => Http::response(null, 204)]);

        $this->client->destroySession('sess-1');

        Http::assertSent(fn ($req) => $req->method() === 'DELETE'
            && str_ends_with($req->url(), '/session/sess-1')
        );
    }

    public function test_destroy_session_silently_ignores_errors(): void
    {
        Http::fake(['*/session/*' => fn () => throw new ConnectionException]);

        // Must not throw
        $this->client->destroySession('sess-1');
        $this->assertTrue(true);
    }

    public function test_ping_returns_true_on_healthy_response(): void
    {
        Http::fake(['*/health' => Http::response(['ok' => true, 'sessions' => 0], 200)]);

        $this->assertTrue($this->client->ping());
    }

    public function test_ping_returns_false_on_connection_failure(): void
    {
        Http::fake(['*/health' => fn () => throw new ConnectionException]);

        $this->assertFalse($this->client->ping());
    }
}
