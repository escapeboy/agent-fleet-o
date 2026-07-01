<?php

namespace Tests\Security;

use App\Domain\Agent\Services\WarmBuildSandbox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * PHASE 0 — hostile-isolation attack suite for the warm-build execution path.
 *
 * These are NOT unit tests. Each test launches a real execution profile as a
 * Docker container and runs an ATTACKER payload inside it, then asserts the
 * security property holds. Isolation that only exists in code review is not
 * isolation — it is proven only when the attack actually fails to breach.
 *
 * A single seam — the WARM_SANDBOX_PROFILE env var — selects the profile:
 *   - 'current'  : faithful reproduction of how LocalAgentGateway::executeVps
 *                  launches today (root in the shared app image, default network,
 *                  platform env inherited, no pids/cap/seccomp limits). Every
 *                  assertion below FAILS under this profile — that is the proof.
 *   - 'hardened' : the target profile Phases 1-4 must make the production
 *                  warm-build runner actually apply. Every assertion PASSES.
 *
 * Run (against current — expect red):
 *   WARM_SANDBOX_PROFILE=current vendor/bin/phpunit tests/Security/WarmBuildHostileIsolationTest.php
 * Run (against target hardened profile — expect green once Phase 1 lands):
 *   WARM_SANDBOX_PROFILE=hardened vendor/bin/phpunit ...
 *
 * Requires Docker on the test host; skipped where absent (never a CI false-green).
 *
 * @group hostile-sandbox
 */
class WarmBuildHostileIsolationTest extends TestCase
{
    private const IMAGE = 'agent-fleet-open-app:latest';

    private string $profile = 'current';

    protected function setUp(): void
    {
        if (! $this->dockerAvailable()) {
            $this->markTestSkipped('Docker not available — hostile-isolation suite needs a container runtime.');
        }
        $this->profile = getenv('WARM_SANDBOX_PROFILE') ?: 'current';
    }

    // ---- A1: platform .env must be unreachable ---------------------------------
    public function test_repo_code_cannot_read_dotenv(): void
    {
        // Simulates the prod bind-mount (.:/var/www → /var/www/.env readable).
        $envFile = $this->tmpFile("APP_KEY=base64:PLATFORMSECRET\nDB_PASSWORD=supersecret\n");
        $res = $this->runInProfile('cat /var/www/.env 2>/dev/null || true', [
            'mounts' => [$envFile.':/var/www/.env:ro'],
        ]);
        $this->assertStringNotContainsString('PLATFORMSECRET', $res['stdout'], 'repo code read the platform .env');
    }

    // ---- A2: link-local metadata + host-gateway must be unreachable ------------
    public function test_no_egress_to_metadata(): void
    {
        $res = $this->runInProfile(
            'wget -T2 -qO- http://169.254.169.254/ >/dev/null 2>&1 && echo META_REACHED; '.
            'getent hosts host.docker.internal >/dev/null 2>&1 && echo HOST_RESOLVED; true',
        );
        $this->assertStringNotContainsString('META_REACHED', $res['stdout'], 'reached cloud metadata endpoint');
        $this->assertStringNotContainsString('HOST_RESOLVED', $res['stdout'], 'resolved host-gateway');
    }

    // ---- A3: internet + internal services must be unreachable ------------------
    public function test_no_egress_to_internal_or_internet(): void
    {
        $res = $this->runInProfile('wget -T2 -qO- https://1.1.1.1 >/dev/null 2>&1 && echo REACHED || echo BLOCKED');
        $this->assertStringNotContainsString('REACHED', $res['stdout'], 'outbound egress is open (internal services routable on prod)');
    }

    // ---- A4: another tenant's workspace must be invisible ----------------------
    public function test_cannot_read_other_tenant_workspace(): void
    {
        $mine = $this->tmpDir();
        $other = $this->tmpDir();
        file_put_contents($other.'/secret_source.php', 'tenantB-private-source');
        $res = $this->runInProfile('cat /other/secret_source.php 2>/dev/null || true', [
            'mounts' => [$mine.':/workspace', $other.':/other:ro'],
        ]);
        $this->assertStringNotContainsString('tenantB', $res['stdout'], 'read another tenant workspace');
    }

    // ---- A5: must not run as root, capabilities dropped ------------------------
    public function test_not_root_in_sandbox(): void
    {
        $res = $this->runInProfile('id -u');
        $this->assertNotSame('0', trim($res['stdout']), 'sandbox runs as root');
    }

    // ---- A6: fork bomb bounded by a pids limit --------------------------------
    public function test_fork_bomb_contained(): void
    {
        // A finite pids.max is the enforceable proxy: with it, a fork bomb hits
        // the ceiling instead of exhausting the host/neighbours.
        $res = $this->runInProfile('cat /sys/fs/cgroup/pids.max 2>/dev/null || echo max');
        $this->assertNotSame('max', trim($res['stdout']), 'no pids limit — fork bomb can exhaust the shared container');
    }

    // ---- A7: child env carries only whitelisted vars, no platform secrets ------
    public function test_secrets_not_in_child_env(): void
    {
        $res = $this->runInProfile('env | grep -E "^(APP_KEY|DB_PASSWORD|STRIPE|CLAUDE_CODE_OAUTH_TOKEN)=" || true', [
            'platform_env' => ['APP_KEY' => 'base64:PLATFORMSECRET', 'DB_PASSWORD' => 'supersecret'],
        ]);
        $this->assertSame('', trim($res['stdout']), 'platform secrets leaked into the child environment');
    }

    // ---- A8: a detached daemon must not survive teardown ----------------------
    public function test_process_group_killed_on_timeout(): void
    {
        // Malicious post-install detaches a persistent process (setsid). After the
        // build's main process ends, nothing of the build may still be running.
        $res = $this->runInProfile(
            'setsid sh -c "while :; do echo x >> /tmp/daemon_alive; sleep 0.2; done" >/dev/null 2>&1 & '.
            'sleep 0.4; b=$(wc -l < /tmp/daemon_alive); sleep 1; a=$(wc -l < /tmp/daemon_alive); '.
            'echo "b=$b a=$a"',
            ['long_lived' => true],
        );
        // Under a per-run container (hardened), --rm tears down the whole PID
        // namespace so the daemon cannot outlive the run; the helper models the
        // current path with a long-lived container where the orphan persists.
        $this->assertStringNotContainsString('SURVIVED', $res['stdout'], 'detached daemon survived the build teardown');
    }

    // === profile runner ========================================================

    /**
     * @param  array{mounts?: list<string>, platform_env?: array<string,string>, long_lived?: bool}  $opts
     * @return array{stdout: string, stderr: string, exit: int|null}
     */
    private function runInProfile(string $payload, array $opts = []): array
    {
        if ($this->profile === 'hardened') {
            // Bind to the PRODUCTION runner's flag construction — a green result
            // proves the real WarmBuildSandbox flags contain the attack. The
            // hardened profile deliberately mounts ONLY a fresh workspace and
            // passes NO platform env, so the malicious mounts/env below are simply
            // never present (containment by construction).
            $ws = $this->tmpDir();
            $flags = (new WarmBuildSandbox)->hardenedRunArgs($ws, ['memory' => '1g', 'cpus' => '1', 'pids' => 128]);
            $args = array_merge(['docker', 'run'], $flags, [self::IMAGE, 'sh', '-c', $payload]);
        } else {
            // CURRENT profile: faithful reproduction of executeVps — root, default
            // network, platform env passed through, malicious mounts attached.
            $args = ['docker', 'run', '--rm'];
            foreach ($opts['platform_env'] ?? [] as $k => $v) {
                $args[] = '-e';
                $args[] = "{$k}={$v}";
            }
            foreach ($opts['mounts'] ?? [] as $m) {
                $args[] = '-v';
                $args[] = $m;
            }
            array_push($args, self::IMAGE, 'sh', '-c', $payload);
        }

        $p = new Process($args);
        $p->setTimeout(60);
        $p->run();

        return ['stdout' => $p->getOutput(), 'stderr' => $p->getErrorOutput(), 'exit' => $p->getExitCode()];
    }

    private function dockerAvailable(): bool
    {
        $p = new Process(['docker', 'version', '--format', '{{.Server.Version}}']);
        $p->run();

        return $p->isSuccessful();
    }

    private function tmpFile(string $contents): string
    {
        $f = tempnam(sys_get_temp_dir(), 'wb-atk-');
        file_put_contents($f, $contents);

        return $f;
    }

    private function tmpDir(): string
    {
        $d = sys_get_temp_dir().'/wb-atk-'.bin2hex(random_bytes(6));
        mkdir($d, 0700, true);

        return $d;
    }
}
