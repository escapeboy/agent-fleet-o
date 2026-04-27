<?php

namespace App\Domain\Approval\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Approval\Enums\ActionProposalStatus;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionProposal extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'actor_user_id',
        'actor_agent_id',
        'target_type',
        'target_id',
        'summary',
        'payload',
        'lineage',
        'risk_level',
        'status',
        'expires_at',
        'decided_by_user_id',
        'decided_at',
        'decision_reason',
        'executed_at',
        'execution_result',
        'execution_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'lineage' => 'array',
            'execution_result' => 'array',
            'status' => ActionProposalStatus::class,
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function actorAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'actor_agent_id');
    }

    public function decidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === ActionProposalStatus::Pending;
    }

    public function isExpired(): bool
    {
        return $this->status === ActionProposalStatus::Expired
            || ($this->expires_at !== null && $this->expires_at->isPast() && $this->isPending());
    }
}
