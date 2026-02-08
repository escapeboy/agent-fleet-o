<?php

namespace App\Domain\Experiment\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Enums\ExecutionMode;
use App\Domain\Skill\Models\Skill;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaybookStep extends Model
{
    use HasUuids;

    protected $fillable = [
        'experiment_id',
        'agent_id',
        'skill_id',
        'order',
        'execution_mode',
        'group_id',
        'conditions',
        'input_mapping',
        'output',
        'status',
        'duration_ms',
        'cost_credits',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'execution_mode' => ExecutionMode::class,
            'conditions' => 'array',
            'input_mapping' => 'array',
            'output' => 'array',
            'duration_ms' => 'integer',
            'cost_credits' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
