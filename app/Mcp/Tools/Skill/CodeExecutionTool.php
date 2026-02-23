<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\WorktreeExecution;
use App\Domain\Skill\Enums\SkillType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class CodeExecutionTool extends Tool
{
    protected string $name = 'code_execution_manage';

    protected string $description = 'Manage code execution skills and worktree executions. List executions, inspect diffs and stdout/stderr, get configuration schema, and list pending approval requests for code changes.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: list_skills | list_executions | get_execution | get_diff | get_config_schema')
                ->enum(['list_skills', 'list_executions', 'get_execution', 'get_diff', 'get_config_schema'])
                ->required(),
            'worktree_execution_id' => $schema->string()
                ->description('For get_execution / get_diff: the WorktreeExecution UUID.'),
            'status' => $schema->string()
                ->description('For list_executions: filter by status (pending_approval, completed, failed, approved, rejected).'),
            'limit' => $schema->integer()
                ->description('For list_executions: max results (default 20).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'action' => 'required|in:list_skills,list_executions,get_execution,get_diff,get_config_schema',
            'worktree_execution_id' => 'nullable|string',
            'status' => 'nullable|string',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        return match ($validated['action']) {
            'list_skills' => $this->listSkills(),
            'list_executions' => $this->listExecutions($validated['status'] ?? null, $validated['limit'] ?? 20),
            'get_execution' => $this->getExecution($validated['worktree_execution_id'] ?? null),
            'get_diff' => $this->getDiff($validated['worktree_execution_id'] ?? null),
            'get_config_schema' => $this->getConfigSchema(),
            default => Response::error('Unknown action.'),
        };
    }

    private function listSkills(): Response
    {
        $skills = Skill::where('type', SkillType::CodeExecution->value)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description', 'configuration']);

        return Response::text(json_encode([
            'count' => $skills->count(),
            'skills' => $skills->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'slug' => $s->slug,
                'description' => $s->description,
                'git_repo_path' => $s->configuration['git_repo_path'] ?? null,
                'base_branch' => $s->configuration['base_branch'] ?? 'main',
                'script' => $s->configuration['script'] ?? null,
                'timeout_seconds' => $s->configuration['timeout_seconds'] ?? 300,
            ]),
        ]));
    }

    private function listExecutions(?string $status, int $limit): Response
    {
        $query = WorktreeExecution::with('skillExecution:id,skill_id,status,created_at')
            ->orderByDesc('created_at')
            ->limit($limit);

        if ($status) {
            $query->where('status', $status);
        }

        $executions = $query->get();

        return Response::text(json_encode([
            'count' => $executions->count(),
            'executions' => $executions->map(fn ($e) => [
                'id' => $e->id,
                'skill_execution_id' => $e->skill_execution_id,
                'branch_name' => $e->branch_name,
                'status' => $e->status,
                'exit_code' => $e->exit_code,
                'result_commit_sha' => $e->result_commit_sha,
                'approval_request_id' => $e->approval_request_id,
                'created_at' => $e->created_at,
            ]),
        ]));
    }

    private function getExecution(?string $worktreeExecutionId): Response
    {
        if (! $worktreeExecutionId) {
            return Response::error('worktree_execution_id is required.');
        }

        $execution = WorktreeExecution::find($worktreeExecutionId);

        if (! $execution) {
            return Response::error('WorktreeExecution not found.');
        }

        $approvalStatus = null;
        if ($execution->approval_request_id) {
            $approval = ApprovalRequest::find($execution->approval_request_id);
            $approvalStatus = $approval?->status?->value;
        }

        return Response::text(json_encode([
            'id' => $execution->id,
            'skill_execution_id' => $execution->skill_execution_id,
            'branch_name' => $execution->branch_name,
            'repo_path' => $execution->repo_path,
            'worktree_path' => $execution->worktree_path,
            'base_commit_sha' => $execution->base_commit_sha,
            'result_commit_sha' => $execution->result_commit_sha,
            'exit_code' => $execution->exit_code,
            'status' => $execution->status,
            'stdout' => $execution->stdout,
            'stderr' => $execution->stderr,
            'approval_request_id' => $execution->approval_request_id,
            'approval_status' => $approvalStatus,
            'created_at' => $execution->created_at,
        ]));
    }

    private function getDiff(?string $worktreeExecutionId): Response
    {
        if (! $worktreeExecutionId) {
            return Response::error('worktree_execution_id is required.');
        }

        $execution = WorktreeExecution::find($worktreeExecutionId);

        if (! $execution) {
            return Response::error('WorktreeExecution not found.');
        }

        return Response::text(json_encode([
            'id' => $execution->id,
            'branch_name' => $execution->branch_name,
            'base_commit_sha' => $execution->base_commit_sha,
            'result_commit_sha' => $execution->result_commit_sha,
            'diff' => $execution->diff ?? '(no changes)',
        ]));
    }

    private function getConfigSchema(): Response
    {
        return Response::text(json_encode([
            'description' => 'Configuration schema for code_execution skill type.',
            'configuration' => [
                'git_repo_path' => [
                    'type' => 'string',
                    'required' => true,
                    'description' => 'Absolute path to the git repository on the host.',
                    'example' => '/repos/myproject',
                ],
                'base_branch' => [
                    'type' => 'string',
                    'default' => 'main',
                    'description' => 'Branch to diff against after execution.',
                ],
                'script' => [
                    'type' => 'string',
                    'required' => true,
                    'description' => 'Shell command or script to run inside the sandbox.',
                    'example' => 'php artisan test --no-ansi',
                ],
                'timeout_seconds' => [
                    'type' => 'integer',
                    'default' => 300,
                    'description' => 'Maximum execution time before the container is killed.',
                ],
                'sandbox' => [
                    'type' => 'object',
                    'description' => 'Docker sandbox configuration.',
                    'properties' => [
                        'image' => ['type' => 'string', 'default' => 'agent-fleet/sandbox:latest'],
                        'memory_limit' => ['type' => 'string', 'default' => '512m'],
                        'cpu_limit' => ['type' => 'string', 'default' => '1'],
                        'network' => ['type' => 'string', 'description' => 'Always "none" — network is disabled.'],
                        'env' => ['type' => 'object', 'description' => 'Environment variables injected into the container.'],
                    ],
                ],
                'require_approval' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => 'Always true for CodeExecution — cannot be set to false.',
                ],
            ],
            'security_notes' => [
                'Network is always disabled (--network none).',
                'All Linux capabilities are dropped (--cap-drop ALL).',
                'Workspace is mounted read-only — scripts cannot modify the worktree directly.',
                'FilesystemGuard scans stdout for secrets before recording output.',
                'An ApprovalRequest is always created — no code change is merged without human review.',
            ],
        ]));
    }
}
