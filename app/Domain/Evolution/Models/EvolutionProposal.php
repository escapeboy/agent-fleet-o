<?php

namespace App\Domain\Evolution\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Evolution\Enums\EvolutionProposalStatus;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvolutionProposal extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'agent_id',
        'execution_id',
        'status',
        'analysis',
        'proposed_changes',
        'reasoning',
        'confidence_score',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => EvolutionProposalStatus::class,
            'proposed_changes' => 'array',
            'confidence_score' => 'float',
            'reviewed_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(AgentExecution::class, 'execution_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
