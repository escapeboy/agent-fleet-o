<?php

namespace App\Domain\Crew\Models;

use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Models\Artifact;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrewExecution extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'crew_id',
        'experiment_id',
        'goal',
        'status',
        'task_plan',
        'final_output',
        'config_snapshot',
        'quality_score',
        'coordinator_iterations',
        'total_cost_credits',
        'duration_ms',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CrewExecutionStatus::class,
            'task_plan' => 'array',
            'final_output' => 'array',
            'config_snapshot' => 'array',
            'quality_score' => 'float',
            'coordinator_iterations' => 'integer',
            'total_cost_credits' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function crew(): BelongsTo
    {
        return $this->belongsTo(Crew::class);
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }

    public function taskExecutions(): HasMany
    {
        return $this->hasMany(CrewTaskExecution::class)->orderBy('sort_order');
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(Artifact::class);
    }

    public function isRunning(): bool
    {
        return $this->status->isActive();
    }

    public function completedTaskCount(): int
    {
        return $this->taskExecutions()
            ->where('status', 'validated')
            ->count();
    }

    public function totalTaskCount(): int
    {
        return $this->taskExecutions()->count();
    }

    public function totalCost(): int
    {
        return $this->total_cost_credits;
    }
}
