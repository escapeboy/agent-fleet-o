<?php

namespace Tests\Feature\Domain\Agent;

use App\Domain\Agent\Services\WarmBuildSandbox;
use Tests\TestCase;

/**
 * Pins the hardened docker flags produced by the warm-build sandbox. This runs
 * in the normal (docker-less) suite; the live containment proof that these flags
 * actually stop the attacks lives in Tests\Security\WarmBuildHostileIsolationTest
 * (@group hostile-sandbox, docker host only).
 */
class WarmBuildSandboxTest extends TestCase
{
    private function pairs(array $args): array
    {
        // Collapse ['--flag','val',...] into "--flag val" strings for easy asserts.
        $out = [];
        for ($i = 0; $i < count($args); $i++) {
            $out[] = $args[$i];
            if (isset($args[$i + 1]) && str_starts_with($args[$i], '--')) {
                $out[] = $args[$i].' '.$args[$i + 1];
            }
        }

        return $out;
    }

    public function test_hardened_args_enforce_the_isolation_controls(): void
    {
        $args = (new WarmBuildSandbox)->hardenedRunArgs('/srv/ws');
        $flat = $this->pairs($args);

        $this->assertContains('--user 65534:65534', $flat, 'must run non-root');
        $this->assertContains('--cap-drop ALL', $flat, 'must drop all capabilities');
        $this->assertContains('--security-opt no-new-privileges', $flat);
        $this->assertContains('--read-only', $args, 'root fs must be read-only');
        $this->assertContains('--network none', $flat, 'default-deny egress (Phase 1)');
        $this->assertContains('--pids-limit 256', $flat, 'fork-bomb ceiling');
        $this->assertContains('--memory 2g', $flat);
        $this->assertContains('--cpus 2', $flat);

        // Only the workspace is writable; tmpfs is nosuid/nodev.
        $joined = implode(' ', $args);
        $this->assertStringContainsString('-v /srv/ws:/workspace:rw', $joined);
        $this->assertContains('--workdir /workspace', $flat);
        $this->assertStringContainsString('--tmpfs /tmp:rw,nosuid,nodev,size=512m', $joined);
    }

    public function test_no_platform_env_is_ever_inherited(): void
    {
        // docker never inherits the daemon env; only explicit -e is passed. The
        // args must carry NO platform secret even if the caller is careless.
        $args = (new WarmBuildSandbox)->hardenedRunArgs('/srv/ws', [
            'env' => ['GIT_TERMINAL_PROMPT' => '0'],
        ]);
        $joined = implode(' ', $args);

        $this->assertStringContainsString('GIT_TERMINAL_PROMPT=0', $joined, 'whitelisted env passes through');
        $this->assertStringNotContainsString('APP_KEY', $joined);
        $this->assertStringNotContainsString('DB_PASSWORD', $joined);
        $this->assertStringNotContainsString('CLAUDE_CODE_OAUTH_TOKEN', $joined);
    }

    public function test_egress_is_fail_closed_without_a_proxy(): void
    {
        // A network without a proxy would hand the sandbox raw network access —
        // the runner must refuse and stay on --network none.
        $args = (new WarmBuildSandbox)->hardenedRunArgs('/srv/ws', ['network' => 'warm-build-egress']);
        $this->assertContains('--network none', $this->pairs($args), 'network without proxy must fail closed');
    }

    public function test_egress_routes_through_allowlist_proxy(): void
    {
        $args = (new WarmBuildSandbox)->hardenedRunArgs('/srv/ws', [
            'network' => 'warm-build-egress',
            'egress_proxy' => 'http://warm-build-egress:3128',
        ]);
        $flat = $this->pairs($args);
        $joined = implode(' ', $args);

        $this->assertContains('--network warm-build-egress', $flat, 'sandbox joins the internal egress network');
        $this->assertStringContainsString('HTTPS_PROXY=http://warm-build-egress:3128', $joined);
        $this->assertStringContainsString('HTTP_PROXY=http://warm-build-egress:3128', $joined);
        // NO_PROXY must be empty so nothing bypasses the allowlist.
        $this->assertStringContainsString('NO_PROXY=', $joined);
        $this->assertStringNotContainsString('NO_PROXY=.', $joined);
    }

    public function test_custom_limits_override_defaults(): void
    {
        $args = (new WarmBuildSandbox)->hardenedRunArgs('/srv/ws', ['pids' => 128, 'memory' => '1g', 'cpus' => '1']);
        $flat = $this->pairs($args);

        $this->assertContains('--pids-limit 128', $flat);
        $this->assertContains('--memory 1g', $flat);
        $this->assertContains('--cpus 1', $flat);
    }
}
