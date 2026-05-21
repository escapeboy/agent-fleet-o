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
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $team_id
 * @property string|null $actor_user_id
 * @property string|null $actor_agent_id
 * @property string $target_type
 * @property string|null $target_id
 * @property string $summary
 * @property array<string, mixed> $payload
 * @property array<int, mixed> $lineage
 * @property string $risk_level
 * @property int|null $rubric_score
 * @property array<string, mixed>|null $rubric_breakdown
 * @property ActionProposalStatus $status
 * @property Carbon|null $expires_at
 * @property string|null $decided_by_user_id
 * @property Carbon|null $decided_at
 * @property string|null $decision_reason
 * @property Carbon|null $executed_at
 * @property array<string, mixed>|null $execution_result
 * @property string|null $execution_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team|null $team
 * @property-read User|null $actorUser
 * @property-read Agent|null $actorAgent
 * @property-read User|null $decidedByUser
 */
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
        'rubric_score',
        'rubric_breakdown',
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
            'rubric_score' => 'integer',
            'rubric_breakdown' => 'array',
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
