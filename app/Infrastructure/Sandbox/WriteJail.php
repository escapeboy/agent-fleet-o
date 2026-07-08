<?php

namespace App\Infrastructure\Sandbox;

use Illuminate\Support\Facades\Log;

/**
 * Write-jail launcher (Shepherd borrow #2). Prefixes a to-be-spawned agent
 * command with a native-sandbox launcher (Linux Landlock) so the process may
 * only WRITE under an explicit allow-list of paths — the warm-build worktree's
 * writable-roots plus its ephemeral HOME and /tmp. Everything else on the host
 * filesystem is read-only to the agent at the kernel level.
 *
 * INERT BY DEFAULT — and defensively inert: wrap() returns the command unchanged
 * whenever the jail is disabled, the OS isn't Linux, or the launcher binary is
 * absent/not-executable. Warm-build must never break because the jail can't be
 * applied; it is defense-in-depth, not a hard dependency. Real kernel enforcement
 * requires the reference helper (base/docker/writejail) built into the image and
 * verified on the VPS; until then the launcher env is unset and this is a no-op.
 */
class WriteJail
{
    /**
     * @param  list<string>  $command  The full command to spawn (binary + args).
     * @param  list<string>  $writablePaths  Absolute paths the jailed process may write.
     * @return list<string> The wrapped command, or $command verbatim when inert.
     */
    public function wrap(array $command, array $writablePaths): array
    {
        $reason = $this->inertReason();
        if ($reason !== null) {
            Log::info('WriteJail: inert — running unjailed', ['reason' => $reason]);

            return $command;
        }

        $launcher = (string) config('experiments.warm_build.write_jail.launcher');
        $prefix = [$launcher];
        foreach ($this->writablePaths($writablePaths) as $path) {
            $prefix[] = '--writable';
            $prefix[] = $path;
        }
        $prefix[] = '--';

        Log::info('WriteJail: jailing agent process', [
            'launcher' => $launcher,
            'writable_count' => count($writablePaths),
        ]);

        return array_merge($prefix, $command);
    }

    public function enabled(): bool
    {
        return (bool) config('experiments.warm_build.write_jail.enabled', false);
    }

    /**
     * Why the jail can't be applied, or null when it can. Ordered cheapest-first.
     */
    public function inertReason(): ?string
    {
        if (! $this->enabled()) {
            return 'disabled';
        }
        if (PHP_OS_FAMILY !== 'Linux') {
            return 'not-linux';
        }
        $launcher = config('experiments.warm_build.write_jail.launcher');
        if (! is_string($launcher) || $launcher === '') {
            return 'launcher-unset';
        }
        if (! @is_executable($launcher)) {
            return 'launcher-missing';
        }

        return null;
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function writablePaths(array $paths): array
    {
        $extra = config('experiments.warm_build.write_jail.extra_writable', []);
        $all = array_merge($paths, is_array($extra) ? $extra : []);

        return array_values(array_unique(array_filter(
            $all,
            static fn ($p): bool => is_string($p) && $p !== '',
        )));
    }
}
