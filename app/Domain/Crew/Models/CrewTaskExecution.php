<?php

namespace App\Domain\Crew\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Enums\CrewTaskStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrewTaskExecution extends Model
{
    use HasUuids;

    protected $fillable = [
        'crew_execution_id',
        'agent_id',
        'title',
        'description',
        'status',
        'input_context',
        'output',
        'qa_feedback',
        'qa_score',
        'depends_on',
        'attempt_number',
        'max_attempts',
        'cost_credits',
        'duration_ms',
        'error_message',
        'sort_order',
        'batch_id',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CrewTaskStatus::class,
            'input_context' => 'array',
            'output' => 'array',
            'qa_feedback' => 'array',
            'qa_score' => 'float',
            'depends_on' => 'array',
            'attempt_number' => 'integer',
            'max_attempts' => 'integer',
            'cost_credits' => 'integer',
            'duration_ms' => 'integer',
            'sort_order' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function crewExecution(): BelongsTo
    {
        return $this->belongsTo(CrewExecution::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function isPending(): bool
    {
        return $this->status === CrewTaskStatus::Pending;
    }

    public function isValidated(): bool
    {
        return $this->status === CrewTaskStatus::Validated;
    }

    public function needsRevision(): bool
    {
        return $this->status === CrewTaskStatus::NeedsRevision;
    }

    public function qaRejected(): bool
    {
        return $this->status === CrewTaskStatus::QaFailed;
    }

    public function canRetry(): bool
    {
        return $this->attempt_number < $this->max_attempts;
    }
}
