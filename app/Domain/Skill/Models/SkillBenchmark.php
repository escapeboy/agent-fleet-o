<?php

namespace App\Domain\Skill\Models;

use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Skill\Enums\BenchmarkStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SkillBenchmark extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'skill_id',
        'team_id',
        'best_version_id',
        'metric_name',
        'metric_direction',
        'baseline_value',
        'best_value',
        'test_inputs',
        'iteration_count',
        'max_iterations',
        'time_budget_seconds',
        'iteration_budget_seconds',
        'complexity_penalty',
        'improvement_threshold',
        'status',
        'started_at',
        'completed_at',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'status' => BenchmarkStatus::class,
            'test_inputs' => 'array',
            'settings' => 'array',
            'baseline_value' => 'float',
            'best_value' => 'float',
            'iteration_count' => 'integer',
            'max_iterations' => 'integer',
            'time_budget_seconds' => 'integer',
            'iteration_budget_seconds' => 'integer',
            'complexity_penalty' => 'float',
            'improvement_threshold' => 'float',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function bestVersion(): BelongsTo
    {
        return $this->belongsTo(SkillVersion::class, 'best_version_id');
    }

    public function iterationLogs(): HasMany
    {
        return $this->hasMany(SkillIterationLog::class, 'benchmark_id')->orderBy('iteration_number');
    }

    public function isRunning(): bool
    {
        return $this->status === BenchmarkStatus::Running;
    }

    public function elapsedSeconds(): int
    {
        if (! $this->started_at) {
            return 0;
        }

        return (int) $this->started_at->diffInSeconds(now());
    }

    public function hasTimeRemaining(): bool
    {
        return $this->elapsedSeconds() < $this->time_budget_seconds;
    }

    public function hasIterationsRemaining(): bool
    {
        return $this->iteration_count < $this->max_iterations;
    }

    public function shouldContinue(): bool
    {
        return $this->isRunning()
            && $this->hasTimeRemaining()
            && $this->hasIterationsRemaining();
    }

    public function improvementPercent(): float
    {
        if ($this->baseline_value === null || $this->baseline_value == 0 || $this->best_value === null) {
            return 0.0;
        }

        $delta = $this->best_value - $this->baseline_value;

        return round(($delta / abs($this->baseline_value)) * 100, 2);
    }
}
