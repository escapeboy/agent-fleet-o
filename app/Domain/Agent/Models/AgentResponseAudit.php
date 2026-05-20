<?php

namespace App\Domain\Agent\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentResponseAudit extends Model
{
    use BelongsToTeam, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'agent_id',
        'team_id',
        'execution_id',
        'step_index',
        'prompt_hash',
        'response_text',
        'tools_called',
        'schema_valid',
        'violations',
    ];

    protected function casts(): array
    {
        return [
            'step_index' => 'integer',
            'schema_valid' => 'boolean',
            'tools_called' => 'array',
            'violations' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
