<?php

namespace App\Domain\Agent\Services;

use Illuminate\Support\Facades\Process;

/**
 * Hostile-isolation execution profile for the warm-build path.
 *
 * Unlike AgentSandbox (read-only, --network none — for capture-only scripts),
 * this profile is WRITABLE on the workspace but adversarially hardened
 * everywhere else, so an untrusted external repo can be built (edit + test)
 * without compromising the host, control plane, or other tenants.
 *
 * `hardenedRunArgs()` is the SINGLE source of truth for the docker flags and is
 * pure (no config/container deps) so the hostile-isolation attack suite binds to
 * exactly what production runs — a green attack test then proves the real flags,
 * not a duplicated spec.
 *
 * Isolation delivered by this profile:
 *   - non-root user (+ --user), --cap-drop ALL, --security-opt no-new-privileges
 *   - docker's default seccomp profile (on unless --privileged)
 *   - --network none by default (Phase 2 replaces this with an egress allowlist)
 *   - --pids-limit, --memory, --cpus (per execution, not shared horizon-wide)
 *   - --read-only root + size-capped --tmpfs; ONLY the workspace is writable
 *   - NO host env inheritance (docker passes only explicit -e), so platform
 *     secrets never reach the child by construction
 *
 * NOT delivered here (later phases / infra): egress allowlist (Phase 2), tenant
 * git-token credential helper (Phase 3), per-tenant mount + process-group
 * teardown + scrub (Phase 4). Enforcing a disk quota on the writable workspace
 * bind-mount needs host-level XFS/prjquota and is NOT enforced by these flags.
 */
class WarmBuildSandbox
{
    /**
     * Build the `docker run` args for the hardened profile, up to (not including)
     * the image and command. Pure — callers pass resolved limits.
     *
     * @param  array{
     *   user?: string, pids?: int, memory?: string, cpus?: string,
     *   tmpfs_size?: string, network?: string, env?: array<string,string>,
     *   extra_mounts?: list<string>, seccomp_profile?: string, egress_proxy?: string
     * }  $opts
     * @return list<string>
     */
    public function hardenedRunArgs(string $workspacePath, array $opts = []): array
    {
        // Egress model (Phase 2): the sandbox joins an `internal: true` network
        // (no route to internet / metadata / host-gateway / control plane) and its
        // ONLY way out is an allowlist CONNECT proxy. If neither a network nor a
        // proxy is configured we fall back to --network none (fail-CLOSED): a
        // misconfiguration must sever egress, never silently open it.
        $network = $opts['network'] ?? 'none';
        $proxy = $opts['egress_proxy'] ?? null;
        // Passing an egress network without a proxy would give the sandbox raw
        // access to that network — refuse it and stay closed.
        if ($network !== 'none' && ! $proxy) {
            $network = 'none';
        }
        $args = [
            '--rm',
            '--user', $opts['user'] ?? '65534:65534',
            '--cap-drop', 'ALL',
            '--security-opt', 'no-new-privileges',
            '--pids-limit', (string) ($opts['pids'] ?? 256),
            '--memory', $opts['memory'] ?? '2g',
            '--cpus', $opts['cpus'] ?? '2',
            '--read-only',
            '--tmpfs', '/tmp:rw,nosuid,nodev,size='.($opts['tmpfs_size'] ?? '512m'),
            // ONLY the workspace is writable; :Z would be for SELinux relabel.
            '-v', $workspacePath.':/workspace:rw',
            '--workdir', '/workspace',
            '--network', $network,
        ];

        // Force ALL http(s) egress through the allowlist proxy; empty NO_PROXY so
        // nothing bypasses it. Direct connections die anyway (internal network),
        // but this makes git/npm/pip/composer/claude actually route out.
        if ($proxy) {
            foreach (['HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy'] as $k) {
                $args[] = '-e';
                $args[] = $k.'='.$proxy;
            }
            $args[] = '-e';
            $args[] = 'NO_PROXY=';
            $args[] = '-e';
            $args[] = 'no_proxy=';
        }

        // docker's DEFAULT seccomp profile is applied automatically (blocks ~44
        // dangerous syscalls) unless --privileged; only override to pin a stricter
        // custom profile shipped with the builder image.
        if (! empty($opts['seccomp_profile'])) {
            $args[] = '--security-opt';
            $args[] = 'seccomp='.$opts['seccomp_profile'];
        }

        // Explicit env ONLY (docker never inherits the daemon env). Never pass
        // platform secrets here — that is the whole point of the profile.
        foreach ($opts['env'] ?? [] as $k => $v) {
            $args[] = '-e';
            $args[] = $k.'='.str_replace(["\n", "\r", "\0"], '', (string) $v);
        }

        foreach ($opts['extra_mounts'] ?? [] as $m) {
            $args[] = '-v';
            $args[] = $m;
        }

        return $args;
    }

    /**
     * Run a command inside the hardened profile.
     *
     * @param  list<string>  $command
     * @param  array<string,mixed>  $opts  see hardenedRunArgs() + image, timeout
     * @return array{exit_code: int|null, stdout: string, stderr: string, timed_out: bool}
     */
    public function run(string $workspacePath, array $command, array $opts = []): array
    {
        $image = $opts['image'] ?? config('agent.warm_build_sandbox.image', 'agent-fleet/warm-build-sandbox:latest');
        $timeout = (int) ($opts['timeout'] ?? config('agent.warm_build_sandbox.timeout_seconds', 1800));

        // Source the egress network + proxy from config unless the caller overrode.
        $opts['network'] ??= (string) config('agent.warm_build_sandbox.egress_network', 'none');
        $opts['egress_proxy'] ??= config('agent.warm_build_sandbox.egress_proxy');

        // Deterministic name so teardown can target the container even if the
        // `docker run` client is killed on timeout and orphans the container.
        $name = (string) ($opts['name'] ?? 'warm-build-'.bin2hex(random_bytes(6)));

        $args = array_merge(
            ['docker', 'run', '--name', $name],
            $this->hardenedRunArgs($workspacePath, $opts),
            [$image],
            $command,
        );

        $timedOut = false;

        try {
            $p = Process::timeout($timeout)->run($args);

            return [
                'exit_code' => $p->exitCode(),
                'stdout' => mb_substr($p->output(), 0, 262_144),
                'stderr' => mb_substr($p->errorOutput(), 0, 32_768),
                'timed_out' => false,
            ];
        } catch (\Throwable $e) {
            $timedOut = str_contains(strtolower($e->getMessage()), 'timed out');

            return [
                'exit_code' => null,
                'stdout' => '',
                'stderr' => mb_substr($e->getMessage(), 0, 32_768),
                'timed_out' => $timedOut,
            ];
        } finally {
            // GUARANTEED teardown (A8): kill the whole container — its entire PID
            // namespace goes with it, so a detached setsid daemon a malicious
            // post-install started cannot outlive the run. Runs on success (where
            // --rm already removed it → no-op), timeout, or crash. tmpfs /tmp +
            // caches are destroyed with the container (scrub for free); the
            // worktree is torn down separately by WarmRepoManager::release().
            Process::timeout(30)->run(['docker', 'rm', '-f', $name]);
        }
    }
}
