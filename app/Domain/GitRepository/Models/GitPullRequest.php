<?php

namespace App\Domain\GitRepository\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Approval\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GitPullRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'git_repository_id',
        'agent_id',
        'approval_request_id',
        'title',
        'body',
        'branch',
        'base_branch',
        'pr_number',
        'pr_url',
        'status',
        'merged_at',
    ];

    protected function casts(): array
    {
        return [
            'merged_at' => 'datetime',
        ];
    }

    public function gitRepository(): BelongsTo
    {
        return $this->belongsTo(GitRepository::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isMerged(): bool
    {
        return $this->status === 'merged';
    }
}
