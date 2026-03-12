<?php

namespace App\Domain\Agent\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentConfigRevision extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'agent_id',
        'team_id',
        'created_by',
        'before_config',
        'after_config',
        'changed_keys',
        'source',
        'rolled_back_from_revision_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'before_config' => 'array',
            'after_config' => 'array',
            'changed_keys' => 'array',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
