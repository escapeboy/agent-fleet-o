<?php

namespace App\Domain\Skill\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkillExecution extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'skill_id',
        'agent_id',
        'experiment_id',
        'team_id',
        'status',
        'input',
        'output',
        'duration_ms',
        'cost_credits',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
            'duration_ms' => 'integer',
            'cost_credits' => 'integer',
        ];
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
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
