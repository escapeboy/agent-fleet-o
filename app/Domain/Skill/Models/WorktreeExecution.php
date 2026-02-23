<?php

namespace App\Domain\Skill\Models;

use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorktreeExecution extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'skill_execution_id',
        'team_id',
        'repo_path',
        'worktree_path',
        'branch_name',
        'base_commit_sha',
        'result_commit_sha',
        'exit_code',
        'stdout',
        'stderr',
        'status',
        'diff',
        'approval_request_id',
    ];

    protected function casts(): array
    {
        return [
            'exit_code' => 'integer',
        ];
    }

    public function skillExecution(): BelongsTo
    {
        return $this->belongsTo(SkillExecution::class);
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    public function isPendingApproval(): bool
    {
        return $this->status === 'pending_approval';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }
}
