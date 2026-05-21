<?php

namespace App\Domain\Project\Models;

use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Project\Enums\ProjectRunStatus;
use App\Domain\Signal\Models\Signal;
use App\Domain\Trigger\Models\TriggerRule;
use App\Models\Artifact;
use Database\Factories\Domain\Project\ProjectRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $project_id
 * @property int $run_number
 * @property string|null $experiment_id
 * @property string|null $crew_execution_id
 * @property string|null $trigger_rule_id
 * @property string|null $signal_id
 * @property string|null $triggered_by_conversation_id
 * @property ProjectRunStatus|null $status
 * @property string $trigger
 * @property array<string, mixed>|null $input_data
 * @property string|null $output_summary
 * @property int $spend_credits
 * @property string|null $error_message
 * @property array<string, mixed>|null $error_metadata
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property int $delegation_depth
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ProjectRun extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory()
    {
        return ProjectRunFactory::new();
    }

    protected $fillable = [
        'project_id',
        'run_number',
        'experiment_id',
        'crew_execution_id',
        'trigger_rule_id',
        'signal_id',
        'triggered_by_conversation_id',
        'status',
        'trigger',
        'input_data',
        'output_summary',
        'spend_credits',
        'error_message',
        'error_metadata',
        'started_at',
        'completed_at',
        'delegation_depth',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProjectRunStatus::class,
            'run_number' => 'integer',
            'input_data' => 'array',
            'spend_credits' => 'integer',
            'delegation_depth' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'error_metadata' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }

    public function crewExecution(): BelongsTo
    {
        return $this->belongsTo(CrewExecution::class);
    }

    public function triggerRule(): BelongsTo
    {
        return $this->belongsTo(TriggerRule::class);
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }

    public function triggeredByConversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'triggered_by_conversation_id');
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(Artifact::class);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function duration(): ?int
    {
        if (! $this->started_at) {
            return null;
        }

        $end = $this->completed_at ?? now();

        return (int) $this->started_at->diffInSeconds($end);
    }

    public function durationForHumans(): string
    {
        $seconds = $this->duration();
        if ($seconds === null) {
            return '-';
        }

        if ($seconds < 60) {
            return $seconds.'s';
        }

        if ($seconds < 3600) {
            return round($seconds / 60).'m';
        }

        return round($seconds / 3600, 1).'h';
    }
}
