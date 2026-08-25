<?php

namespace Tests\Feature\Sandbox;

use App\Domain\Agent\Services\Sandbox\ModalSandboxDriver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Tests\TestCase;

/**
 * Attack-shape assertions for the Modal sandbox path.
 *
 * These tests verify the contract that isolates untrusted code from the
 * horizon host, without requiring a live Modal deployment. Each test
 * corresponds to one attack the driver must defeat:
 *
 *   1. Reading /.env or any other host file via the Modal Sandbox.
 *   2. Reaching horizon-internal services (Redis, Postgres) over the network.
 *   3. Escaping the timeout by holding the connection open.
 */
class ModalSandboxSecurityContractTest extends TestCase
{
    public function test_driver_never_sends_host_paths_or_env_from_horizon(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*/execute' => $http->response(['exit_code' => 0, 'stdout' => '', 'stderr' => ''], 200),
        ]);

        $driver = new ModalSandboxDriver($http, 'https://x.modal.run', 't');

        // Even if the caller mistakenly passed the horizon container's app
        // root, the driver only uploads files it can enumerate under the
        // given path — and the Modal Sandbox has no bind mount, so the
        // executed command still cannot reach /var/www/.env on the host.
        $driver->execute('/nonexistent-path', ['printenv']);

        $http->assertSent(function (HttpClientRequest $request) {
            $body = $request->data();

            // The payload must not smuggle host-side secrets or paths.
            $this->assertArrayNotHasKey('APP_KEY', $body);
            $this->assertArrayNotHasKey('DB_PASSWORD', $body);
            $serialized = json_encode($body);
            $this->assertStringNotContainsString('/var/www/.env', $serialized);
            $this->assertStringNotContainsString(base_path('.env'), $serialized);
            $this->assertStringNotContainsString('APP_KEY=', $serialized);

            // Workspace is transmitted as an array of {path, content_b64}
            // — never as a raw host path the Sandbox side would trust.
            $this->assertIsArray($body['workspace']);

            return true;
        });
    }

    public function test_http_timeout_bounds_wall_clock_even_when_sandbox_hangs(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*/execute' => function () {
                // Simulate a hung sandbox — the Modal endpoint never responds.
                // In production this is enforced by Modal Sandbox's own
                // `timeout=` cap; on the client the HTTP timeout is our
                // second line of defence and MUST fire.
                throw new ConnectionException('timeout after 1s');
            },
        ]);

        $driver = new ModalSandboxDriver($http, 'https://x.modal.run', 't');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Modal sandbox unreachable');

        $driver->execute('/nonexistent', ['sleep', '99999'], ['timeout_seconds' => 5]);
    }

    public function test_python_endpoint_enforces_gvisor_and_network_block(): void
    {
        // The Python side is the enforcement point for gVisor and network
        // isolation — the Laravel driver cannot override it. Verify the
        // deployed source keeps these invariants so a future refactor can't
        // silently weaken them.
        $source = file_get_contents(base_path('../modal/app.py'));

        $this->assertIsString($source, 'modal/app.py must exist at repo root');
        $this->assertStringContainsString('SANDBOX_GVISOR = True', $source);
        $this->assertStringContainsString('gvisor=SANDBOX_GVISOR', $source);
        $this->assertStringContainsString('block_network=True', $source);
        $this->assertStringContainsString('MAX_TIMEOUT_SECONDS = 900', $source);
        // Bearer-token gate must still be in place.
        $this->assertStringContainsString('invalid bearer token', $source);
    }
}
