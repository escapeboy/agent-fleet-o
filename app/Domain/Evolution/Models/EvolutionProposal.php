<?php

namespace App\Domain\Evolution\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Evolution\Enums\EvolutionProposalStatus;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Skill\Models\Skill;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $team_id
 * @property string $agent_id
 * @property string|null $execution_id
 * @property EvolutionProposalStatus $status
 * @property string|null $analysis
 * @property array|null $proposed_changes
 * @property string|null $reasoning
 * @property float|null $confidence_score
 * @property string|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Agent $agent
 * @property-read AgentExecution|null $execution
 */
class EvolutionProposal extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'agent_id',
        'execution_id',
        'skill_id',
        'trigger',
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
            'trigger' => 'string',
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

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
