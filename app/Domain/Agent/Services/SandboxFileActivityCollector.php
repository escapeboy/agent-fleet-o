<?php

namespace App\Domain\Agent\Services;

use App\Domain\Agent\Models\SandboxFileActivity;

/**
 * Captures the files an agent produced in its sandbox workspace and records
 * them as SandboxFileActivity rows so they surface in the unified timeline.
 *
 * Kanwas-inspired sprint — observability MVP. One-directional only: it reads
 * the sandbox outputs directory once, after the agent run, before teardown.
 * Real-time filesystem watching and reverse sync are a deferred spike.
 *
 * Security: symlinks are skipped and every file's real path must resolve
 * inside the outputs directory, so an agent cannot plant links to the host
 * filesystem or another workspace. A scan cap bounds symlink cycles.
 *
 * Cloud-safe: a no-op (returns 0) when the workspace has no outputs directory,
 * which is the case when local agents / filesystem sandboxes are disabled.
 */
final class SandboxFileActivityCollector
{
    /** Hard cap on files recorded per run — bounds the cost on runaway agents. */
    public const MAX_FILES = 200;

    /** Hard cap on directory entries scanned — bounds symlink cycles. */
    public const MAX_SCAN = 5000;

    /**
     * @param  array{team_id: string, agent_id?: string|null, experiment_id?: string|null, sandbox_id?: string|null}  $context
     * @return int Number of file activities recorded.
     */
    public function collect(SandboxedWorkspace $workspace, array $context): int
    {
        $realPrefix = realpath($workspace->outputsDir());

        if ($realPrefix === false || ! is_dir($realPrefix)) {
            return 0;
        }

        $prefix = $realPrefix.DIRECTORY_SEPARATOR;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realPrefix, \FilesystemIterator::SKIP_DOTS),
        );

        $now = now();
        $recorded = 0;
        $scanned = 0;

        foreach ($iterator as $file) {
            if (++$scanned > self::MAX_SCAN) {
                break;
            }

            // Skip symlinks; the real path must resolve inside the sandbox.
            if (! $file->isFile() || $file->isLink()) {
                continue;
            }

            $real = $file->getRealPath();
            if ($real === false || ! str_starts_with($real, $prefix)) {
                continue;
            }

            if ($recorded >= self::MAX_FILES) {
                break;
            }

            $size = $file->getSize();

            SandboxFileActivity::create([
                'team_id' => $context['team_id'],
                'experiment_id' => $context['experiment_id'] ?? null,
                'agent_id' => $context['agent_id'] ?? null,
                'sandbox_id' => $context['sandbox_id'] ?? $workspace->executionId(),
                'path' => substr($real, strlen($prefix)),
                'operation' => 'created',
                'size_bytes' => $size === false ? null : $size,
                'captured_at' => $now,
            ]);

            $recorded++;
        }

        return $recorded;
    }
}
