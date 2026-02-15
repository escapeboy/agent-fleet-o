<?php

namespace App\Domain\Project\Models;

use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Project\Enums\ProjectRunStatus;
use App\Models\Artifact;
use Database\Factories\Domain\Project\ProjectRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'status',
        'trigger',
        'input_data',
        'output_summary',
        'spend_credits',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProjectRunStatus::class,
            'run_number' => 'integer',
            'input_data' => 'array',
            'spend_credits' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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

        return $this->started_at->diffInSeconds($end);
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
