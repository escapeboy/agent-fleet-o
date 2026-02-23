<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Agent\Services\AgentSandbox;
use App\Domain\Agent\Services\FilesystemGuard;
use App\Domain\Agent\Services\WorktreeManager;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillExecution;
use App\Domain\Skill\Models\WorktreeExecution;
use Illuminate\Support\Str;

/**
 * Executes a CodeExecution skill via the Git worktree + Docker sandbox pipeline:
 *
 *   1. Create isolated Git worktree on a fresh branch
 *   2. Run the configured script inside a Docker sandbox
 *      (--network none, --cap-drop ALL, --read-only, memory/CPU limits)
 *   3. Scan output for accidental secret leakage (FilesystemGuard)
 *   4. Commit any changes in the worktree
 *   5. Create a mandatory ApprovalRequest for human diff review
 *   6. Clean up the worktree (always, in finally block)
 *
 * Code execution costs 0 credits (no LLM calls). The RiskLevel for all
 * CodeExecution skills is always High — approval cannot be skipped.
 *
 * Queue: callers that wrap this action in a queued job should dispatch to the
 * 'code-execution' queue (see config/horizon.php: supervisor-code-execution)
 * which has a 660s timeout — well above the max Docker sandbox timeout (300s).
 */
class ExecuteCodeExecutionSkillAction
{
    public function __construct(
        private readonly WorktreeManager $worktreeManager,
        private readonly AgentSandbox $sandbox,
        private readonly FilesystemGuard $filesystemGuard,
    ) {}

    /**
     * @return array{execution: SkillExecution, output: array|null}
     */
    public function execute(
        Skill $skill,
        array $input,
        string $teamId,
        string $userId,
        ?string $agentId = null,
        ?string $experimentId = null,
    ): array {
        $team = Team::find($teamId);

        if ($team && ! $team->hasFeature('code_execution_enabled')) {
            return $this->failExecution(
                $skill, $teamId, $agentId, $experimentId, $input,
                'Code execution requires Pro plan or higher.',
            );
        }

        $config = $skill->configuration ?? [];
        $repoPath = $config['git_repo_path'] ?? null;
        $script = $input['script'] ?? $config['script'] ?? null;
        $baseBranch = $config['base_branch'] ?? 'main';
        $sandboxConfig = array_merge($config['sandbox'] ?? [], [
            'timeout_seconds' => $config['timeout_seconds'] ?? 300,
        ]);

        if (! $repoPath || ! $script) {
            return $this->failExecution(
                $skill, $teamId, $agentId, $experimentId, $input,
                'Missing required configuration: git_repo_path and script.',
            );
        }

        if (! is_dir($repoPath)) {
            return $this->failExecution(
                $skill, $teamId, $agentId, $experimentId, $input,
                "Repository path does not exist: {$repoPath}",
            );
        }

        $executionId = (string) Str::uuid();
        $branchName = 'agent/'.substr($executionId, 0, 8);
        $startTime = hrtime(true);
        $worktreePath = null;

        try {
            // 1. Record the base commit before any changes
            $baseCommit = $this->worktreeManager->getBaseCommit($repoPath);

            // 2. Create an isolated worktree on a new branch
            $worktreePath = $this->worktreeManager->create($executionId, $branchName, $repoPath);

            // 3. Execute the script inside the Docker sandbox
            $sandboxResult = $this->sandbox->execute($worktreePath, $script, $sandboxConfig);

            // 4. Scan stdout for accidental secret leakage
            $violations = $this->filesystemGuard->scanOutput($sandboxResult['stdout']);
            if (! empty($violations)) {
                $sandboxResult['stderr'] .= "\n\n[FilesystemGuard violations]\n".implode("\n", $violations);
                $sandboxResult['exit_code'] = 1;
            }

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $succeeded = $sandboxResult['exit_code'] === 0;

            // 5. Commit changes if execution succeeded and the worktree is dirty
            $resultCommit = null;
            $diff = null;
            if ($succeeded) {
                $diff = $this->worktreeManager->diff($worktreePath, $baseBranch);
                if (! empty(trim($diff))) {
                    $resultCommit = $this->worktreeManager->commit(
                        $worktreePath,
                        "agent: {$skill->name} [{$executionId}]",
                    );
                }
            }

            // 6. Persist SkillExecution record
            $skillExecution = SkillExecution::create([
                'id' => $executionId,
                'skill_id' => $skill->id,
                'agent_id' => $agentId,
                'experiment_id' => $experimentId,
                'team_id' => $teamId,
                'status' => $succeeded ? 'completed' : 'failed',
                'input' => $input,
                'output' => [
                    'exit_code' => $sandboxResult['exit_code'],
                    'stdout' => $sandboxResult['stdout'],
                    'stderr' => $sandboxResult['stderr'],
                ],
                'duration_ms' => $durationMs,
                'cost_credits' => 0,
                'error_message' => $succeeded
                    ? null
                    : ('Exit code '.$sandboxResult['exit_code'].': '.mb_substr($sandboxResult['stderr'], 0, 255)),
            ]);

            // 7. Persist WorktreeExecution record
            $worktreeExecution = WorktreeExecution::create([
                'skill_execution_id' => $skillExecution->id,
                'team_id' => $teamId,
                'repo_path' => $repoPath,
                'worktree_path' => $worktreePath,
                'branch_name' => $branchName,
                'base_commit_sha' => $baseCommit,
                'result_commit_sha' => $resultCommit,
                'exit_code' => $sandboxResult['exit_code'],
                'stdout' => $sandboxResult['stdout'],
                'stderr' => $sandboxResult['stderr'],
                'status' => $succeeded ? 'completed' : 'failed',
                'diff' => $diff,
            ]);

            // 8. Always create an ApprovalRequest on success — diff must be human-reviewed
            if ($succeeded) {
                $approvalRequest = ApprovalRequest::create([
                    'team_id' => $teamId,
                    'experiment_id' => $experimentId,
                    'status' => ApprovalStatus::Pending,
                    'context' => [
                        'type' => 'code_execution',
                        'skill_id' => $skill->id,
                        'skill_name' => $skill->name,
                        'worktree_execution_id' => $worktreeExecution->id,
                        'branch_name' => $branchName,
                        'base_commit_sha' => $baseCommit,
                        'result_commit_sha' => $resultCommit,
                        'script' => $script,
                        'exit_code' => $sandboxResult['exit_code'],
                    ],
                    'expires_at' => now()->addHours(48),
                ]);

                $worktreeExecution->update([
                    'status' => 'pending_approval',
                    'approval_request_id' => $approvalRequest->id,
                ]);
            }

            // 9. Update skill execution stats
            $skill->recordExecution($succeeded, $durationMs);

            return [
                'execution' => $skillExecution,
                'output' => $skillExecution->output,
            ];

        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $skill->recordExecution(false, $durationMs);

            return $this->failExecution(
                $skill, $teamId, $agentId, $experimentId, $input,
                $e->getMessage(), $durationMs,
            );
        } finally {
            // Best-effort worktree cleanup — admin can run `git worktree prune` if this fails
            if ($worktreePath !== null && is_dir($worktreePath)) {
                try {
                    $this->worktreeManager->remove($worktreePath, $repoPath ?? '');
                } catch (\Throwable) {
                    // Non-fatal
                }
            }
        }
    }

    /**
     * @return array{execution: SkillExecution, output: null}
     */
    private function failExecution(
        Skill $skill,
        string $teamId,
        ?string $agentId,
        ?string $experimentId,
        array $input,
        string $errorMessage,
        int $durationMs = 0,
    ): array {
        $execution = SkillExecution::create([
            'skill_id' => $skill->id,
            'agent_id' => $agentId,
            'experiment_id' => $experimentId,
            'team_id' => $teamId,
            'status' => 'failed',
            'input' => $input,
            'output' => null,
            'duration_ms' => $durationMs,
            'cost_credits' => 0,
            'error_message' => $errorMessage,
        ]);

        return [
            'execution' => $execution,
            'output' => null,
        ];
    }
}
