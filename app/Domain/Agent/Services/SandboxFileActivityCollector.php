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
 * Cloud-safe: a no-op (returns 0) when the workspace has no outputs directory,
 * which is the case when local agents / filesystem sandboxes are disabled.
 */
final class SandboxFileActivityCollector
{
    /** Hard cap on files recorded per run — bounds the cost on runaway agents. */
    public const MAX_FILES = 200;

    /**
     * @param  array{team_id: string, agent_id?: string|null, experiment_id?: string|null, sandbox_id?: string|null}  $context
     * @return int Number of file activities recorded.
     */
    public function collect(SandboxedWorkspace $workspace, array $context): int
    {
        $dir = $workspace->outputsDir();

        if (! is_dir($dir)) {
            return 0;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        $prefix = rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $now = now();
        $recorded = 0;

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if ($recorded >= self::MAX_FILES) {
                break;
            }

            SandboxFileActivity::create([
                'team_id' => $context['team_id'],
                'experiment_id' => $context['experiment_id'] ?? null,
                'agent_id' => $context['agent_id'] ?? null,
                'sandbox_id' => $context['sandbox_id'] ?? $workspace->executionId(),
                'path' => str_replace($prefix, '', $file->getPathname()),
                'operation' => 'created',
                'size_bytes' => $file->getSize() ?: null,
                'captured_at' => $now,
            ]);

            $recorded++;
        }

        return $recorded;
    }
}
