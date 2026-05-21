<?php

namespace App\Domain\Crew\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\Models\ExternalAgent;
use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Experiment\Models\WorklogEntry;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $crew_execution_id
 * @property string|null $agent_id
 * @property string|null $external_agent_id
 * @property string $title
 * @property string $description
 * @property CrewTaskStatus $status
 * @property array<string, mixed>|null $input_context
 * @property array<string, mixed>|null $output
 * @property array<string, mixed>|null $qa_feedback
 * @property float|null $qa_score
 * @property array<int, string>|null $depends_on
 * @property array<string, mixed>|null $skip_condition
 * @property int $attempt_number
 * @property int $max_attempts
 * @property int $cost_credits
 * @property int|null $duration_ms
 * @property string|null $error_message
 * @property array<string, mixed>|null $error_metadata
 * @property int $sort_order
 * @property string|null $batch_id
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $claimed_at
 * @property array<string, mixed>|null $belief_state
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CrewExecution $crewExecution
 * @property-read Agent|null $agent
 * @property-read ExternalAgent|null $externalAgent
 */
class CrewTaskExecution extends Model
{
    use HasUuids;

    protected $fillable = [
        'crew_execution_id',
        'agent_id',
        'external_agent_id',
        'title',
        'description',
        'status',
        'input_context',
        'output',
        'qa_feedback',
        'qa_score',
        'depends_on',
        'skip_condition',
        'attempt_number',
        'max_attempts',
        'cost_credits',
        'duration_ms',
        'error_message',
        'error_metadata',
        'sort_order',
        'batch_id',
        'started_at',
        'completed_at',
        'claimed_at',
        'belief_state',
    ];

    protected function casts(): array
    {
        return [
            'status' => CrewTaskStatus::class,
            'input_context' => 'array',
            'output' => 'array',
            'qa_feedback' => 'array',
            'qa_score' => 'float',
            'belief_state' => 'array',
            'depends_on' => 'array',
            'skip_condition' => 'array',
            'error_metadata' => 'array',
            'attempt_number' => 'integer',
            'max_attempts' => 'integer',
            'cost_credits' => 'integer',
            'duration_ms' => 'integer',
            'sort_order' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'claimed_at' => 'datetime',
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

    public function externalAgent(): BelongsTo
    {
        return $this->belongsTo(ExternalAgent::class);
    }

    public function isExternal(): bool
    {
        return $this->external_agent_id !== null;
    }

    public function isPending(): bool
    {
        return $this->status === CrewTaskStatus::Pending;
    }

    public function isBlocked(): bool
    {
        return $this->status === CrewTaskStatus::Blocked;
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

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function worklogEntries(): HasMany
    {
        return $this->hasMany(WorklogEntry::class, 'workloggable_id')
            ->where('workloggable_type', self::class)
            ->orderBy('created_at');
    }
}
