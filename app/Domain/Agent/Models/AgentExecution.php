<?php

namespace App\Domain\Agent\Models;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentExecution extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'agent_id',
        'experiment_id',
        'team_id',
        'status',
        'input',
        'output',
        'skills_executed',
        'tools_used',
        'tool_calls_count',
        'llm_steps_count',
        'duration_ms',
        'cost_credits',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
            'skills_executed' => 'array',
            'tools_used' => 'array',
            'tool_calls_count' => 'integer',
            'llm_steps_count' => 'integer',
            'duration_ms' => 'integer',
            'cost_credits' => 'integer',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }
}
