<?php

namespace App\Domain\Agent\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Manages the lifecycle of Git worktrees used for isolated code execution.
 * All git commands use array-form Process calls to prevent shell injection.
 */
class WorktreeManager
{
    private const WORKTREE_BASE_DIR = '/tmp/agent-fleet-worktrees';

    /**
     * Create a new worktree with a dedicated branch for the agent execution.
     *
     * @return string The absolute path to the created worktree
     */
    public function create(string $agentExecutionId, string $branchName, string $repoPath): string
    {
        $worktreePath = self::WORKTREE_BASE_DIR.'/'.substr($agentExecutionId, 0, 8).'-'.$branchName;

        if (! is_dir(self::WORKTREE_BASE_DIR)) {
            mkdir(self::WORKTREE_BASE_DIR, 0755, true);
        }

        $result = Process::run(['git', '-C', $repoPath, 'worktree', 'add', '-b', $branchName, $worktreePath]);

        if (! $result->successful()) {
            throw new RuntimeException('Failed to create worktree: '.$result->errorOutput());
        }

        return $worktreePath;
    }

    /**
     * Get the current HEAD commit SHA of the repo.
     */
    public function getBaseCommit(string $repoPath): string
    {
        $result = Process::run(['git', '-C', $repoPath, 'rev-parse', 'HEAD']);

        if (! $result->successful()) {
            throw new RuntimeException('Failed to get base commit: '.$result->errorOutput());
        }

        return trim($result->output());
    }

    /**
     * Stage all changes and commit. Returns the new commit SHA.
     */
    public function commit(string $worktreePath, string $message): string
    {
        $stageResult = Process::run(['git', '-C', $worktreePath, 'add', '-A']);

        if (! $stageResult->successful()) {
            throw new RuntimeException('Failed to stage changes: '.$stageResult->errorOutput());
        }

        $commitResult = Process::run(['git', '-C', $worktreePath, 'commit', '-m', $message, '--allow-empty']);

        if (! $commitResult->successful()) {
            throw new RuntimeException('Failed to commit: '.$commitResult->errorOutput());
        }

        $shaResult = Process::run(['git', '-C', $worktreePath, 'rev-parse', 'HEAD']);

        return trim($shaResult->output());
    }

    /**
     * Generate a unified diff between the base branch and the worktree HEAD.
     */
    public function diff(string $worktreePath, string $baseBranch): string
    {
        $result = Process::run(['git', '-C', $worktreePath, 'diff', $baseBranch.'...HEAD']);

        return $result->output();
    }

    /**
     * Remove the worktree and optionally clean up the associated branch.
     */
    public function remove(string $worktreePath, string $repoPath): void
    {
        Process::run(['git', '-C', $repoPath, 'worktree', 'remove', '--force', $worktreePath]);
    }
}
