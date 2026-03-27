<?php

namespace App\Domain\Crew\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrewAgentMessage extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'crew_execution_id',
        'sender_agent_id',
        'recipient_agent_id',
        'parent_message_id',
        'message_type',
        'round',
        'content',
        'is_read',
    ];

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'is_read' => 'boolean',
        ];
    }

    public function crewExecution(): BelongsTo
    {
        return $this->belongsTo(CrewExecution::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'sender_agent_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'recipient_agent_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_message_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_message_id');
    }
}
