<?php

namespace App\Domain\Experiment\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Models\Crew;
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
        'crew_id',
        'workflow_node_id',
        'order',
        'execution_mode',
        'group_id',
        'conditions',
        'input_mapping',
        'output',
        'checkpoint_data',
        'status',
        'duration_ms',
        'cost_credits',
        'loop_count',
        'error_message',
        'started_at',
        'completed_at',
        'last_heartbeat_at',
        'worker_id',
        'idempotency_key',
        'checkpoint_version',
    ];

    protected function casts(): array
    {
        return [
            'execution_mode' => ExecutionMode::class,
            'conditions' => 'array',
            'input_mapping' => 'array',
            'output' => 'array',
            'checkpoint_data' => 'array',
            'duration_ms' => 'integer',
            'cost_credits' => 'integer',
            'checkpoint_version' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
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

    public function crew(): BelongsTo
    {
        return $this->belongsTo(Crew::class);
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

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function isSkipped(): bool
    {
        return $this->status === 'skipped';
    }

    public function isWaitingHuman(): bool
    {
        return $this->status === 'waiting_human';
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'skipped']);
    }

    public function hasCheckpoint(): bool
    {
        return ! empty($this->checkpoint_data);
    }
}
