<?php

namespace App\Infrastructure\AI\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CircuitBreakerState extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'agent_id',
        'state',
        'failure_count',
        'success_count',
        'last_failure_at',
        'opened_at',
        'half_open_at',
        'cooldown_seconds',
        'failure_threshold',
    ];

    protected function casts(): array
    {
        return [
            'failure_count' => 'integer',
            'success_count' => 'integer',
            'cooldown_seconds' => 'integer',
            'failure_threshold' => 'integer',
            'last_failure_at' => 'datetime',
            'opened_at' => 'datetime',
            'half_open_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
