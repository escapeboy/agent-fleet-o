<?php

namespace App\Domain\Agent\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use App\Models\User;
use Database\Factories\Domain\Agent\AgentPolicyVersionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable snapshot of a policy's rules at a point in time. Pinned onto
 * ActionProposal.agent_policy_version_id so a routing decision can always be
 * re-explained against the exact rules that were in force.
 *
 * @property int $version
 * @property array<string, mixed> $rules
 */
class AgentPolicyVersion extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'team_id',
        'agent_policy_id',
        'version',
        'created_by',
        'rules',
        'notes',
        'rolled_back_from_version_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'rules' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function newFactory(): AgentPolicyVersionFactory
    {
        return AgentPolicyVersionFactory::new();
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AgentPolicy::class, 'agent_policy_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
