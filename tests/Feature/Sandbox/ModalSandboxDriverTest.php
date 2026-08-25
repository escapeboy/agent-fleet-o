<?php

namespace Tests\Feature\Sandbox;

use App\Domain\Agent\Services\Sandbox\ModalSandboxDriver;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpClientRequest;
use RuntimeException;
use Tests\TestCase;

class ModalSandboxDriverTest extends TestCase
{
    public function test_execute_posts_to_endpoint_with_bearer_and_returns_normalized_payload(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*/execute' => $http->response([
                'exit_code' => 0,
                'stdout' => "hello\n",
                'stderr' => '',
                'image_digest' => 'python:3.12-slim',
                'cost_estimate_usd' => 0.00042,
            ], 200),
        ]);

        $driver = new ModalSandboxDriver(
            http: $http,
            endpointUrl: 'https://user--fleetq-sandbox-execute.modal.run',
            endpointToken: 's3cret',
            defaultImage: 'python:3.12-slim',
            defaultRegion: 'eu',
        );

        $result = $driver->execute('/nonexistent-path-so-workspace-is-empty', ['echo', 'hello'], [
            'timeout_seconds' => 30,
            'memory_limit' => '256m',
            'cpu_limit' => '0.5',
        ]);

        $this->assertSame(0, $result['exit_code']);
        $this->assertSame("hello\n", $result['stdout']);
        $this->assertSame('modal', $result['driver']);
        $this->assertSame(0.00042, $result['cost_estimate_usd']);
        $this->assertArrayHasKey('correlation_id', $result);
        $this->assertArrayHasKey('duration_ms', $result);

        $http->assertSent(function (HttpClientRequest $request) {
            $this->assertSame('Bearer s3cret', $request->header('Authorization')[0] ?? null);
            $this->assertStringEndsWith('/execute', $request->url());
            $body = $request->data();
            $this->assertSame(30, $body['timeout_seconds']);
            $this->assertSame(256, $body['memory_mb']);
            $this->assertSame(0.5, $body['cpu']);
            $this->assertSame('eu', $body['region']);
            $this->assertSame(['echo', 'hello'], $body['command']);

            return true;
        });
    }

    public function test_execute_wraps_string_command_in_shell(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*/execute' => $http->response(['exit_code' => 0, 'stdout' => '', 'stderr' => ''], 200),
        ]);

        $driver = new ModalSandboxDriver($http, 'https://x.modal.run', 't');
        $driver->execute('/nonexistent', 'echo hi | wc -c');

        $http->assertSent(function (HttpClientRequest $request) {
            $this->assertSame(['/bin/sh', '-c', 'echo hi | wc -c'], $request->data()['command']);

            return true;
        });
    }

    public function test_execute_throws_when_endpoint_config_missing(): void
    {
        $driver = new ModalSandboxDriver(new HttpFactory, '', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MODAL_ENDPOINT_URL');

        $driver->execute('/nonexistent', ['true']);
    }

    public function test_execute_throws_on_http_error(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*/execute' => $http->response(['error' => 'boom'], 502),
        ]);

        $driver = new ModalSandboxDriver($http, 'https://x.modal.run', 't');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 502');

        $driver->execute('/nonexistent', ['true']);
    }

    public function test_env_control_characters_are_stripped_before_upload(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*/execute' => $http->response(['exit_code' => 0, 'stdout' => '', 'stderr' => ''], 200),
        ]);

        $driver = new ModalSandboxDriver($http, 'https://x.modal.run', 't');
        $driver->execute('/nonexistent', ['env'], [
            'env' => [
                'SAFE' => "value\ninjection\rattempt\0",
            ],
        ]);

        $http->assertSent(function (HttpClientRequest $request) {
            $env = $request->data()['env'];
            $this->assertSame('valueinjectionattempt', $env['SAFE']);

            return true;
        });
    }

    public function test_memory_limit_parses_gb_and_mb_suffixes(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*/execute' => $http->response(['exit_code' => 0, 'stdout' => '', 'stderr' => ''], 200),
        ]);

        $driver = new ModalSandboxDriver($http, 'https://x.modal.run', 't');

        $driver->execute('/nonexistent', ['true'], ['memory_limit' => '2g']);
        $http->assertSent(fn (HttpClientRequest $r) => $r->data()['memory_mb'] === 2048);

        $driver->execute('/nonexistent', ['true'], ['memory_limit' => '768m']);
        $http->assertSent(fn (HttpClientRequest $r) => $r->data()['memory_mb'] === 768);
    }
}
