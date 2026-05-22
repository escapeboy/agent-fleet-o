<?php

namespace App\Domain\Agent\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentRuntimeState extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'agent_id',
        'team_id',
        'session_id',
        'state_json',
        'total_input_tokens',
        'total_output_tokens',
        'total_cached_tokens',
        'total_cost_credits',
        'total_executions',
        'last_error',
        'last_active_at',
    ];

    protected function casts(): array
    {
        return [
            'state_json' => 'array',
            'total_input_tokens' => 'integer',
            'total_output_tokens' => 'integer',
            'total_cached_tokens' => 'integer',
            'total_cost_credits' => 'integer',
            'total_executions' => 'integer',
            'last_active_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
