<?php

namespace App\Domain\Project\Models;

use App\Domain\Project\Enums\OverlapPolicy;
use App\Domain\Project\Enums\ScheduleFrequency;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class ProjectSchedule extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id',
        'frequency',
        'cron_expression',
        'interval_minutes',
        'timezone',
        'overlap_policy',
        'max_consecutive_failures',
        'catchup_missed',
        'run_immediately',
        'enabled',
        'last_run_at',
        'next_run_at',
    ];

    protected function casts(): array
    {
        return [
            'frequency' => ScheduleFrequency::class,
            'overlap_policy' => OverlapPolicy::class,
            'interval_minutes' => 'integer',
            'max_consecutive_failures' => 'integer',
            'catchup_missed' => 'boolean',
            'run_immediately' => 'boolean',
            'enabled' => 'boolean',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function resolvedCronExpression(): ?string
    {
        if ($this->cron_expression) {
            return $this->cron_expression;
        }

        return $this->frequency->toCronExpression();
    }

    public function isDue(): bool
    {
        if (! $this->enabled || ! $this->next_run_at) {
            return false;
        }

        return $this->next_run_at->lte(now());
    }

    public function calculateNextRunAt(?Carbon $from = null): ?Carbon
    {
        $expression = $this->resolvedCronExpression();
        if (! $expression) {
            return null;
        }

        $from = $from ?? now();
        $cron = new CronExpression($expression);

        $nextRun = Carbon::instance(
            $cron->getNextRunDate($from->setTimezone($this->timezone)),
        )->setTimezone('UTC');

        return $nextRun;
    }

    public function getNextRunTimes(int $count = 5): array
    {
        $expression = $this->resolvedCronExpression();
        if (! $expression) {
            return [];
        }

        $cron = new CronExpression($expression);
        $times = [];
        $from = now()->setTimezone($this->timezone);

        for ($i = 0; $i < $count; $i++) {
            $nextRun = Carbon::instance($cron->getNextRunDate($from));
            $times[] = $nextRun->copy()->setTimezone('UTC');
            $from = $nextRun->addSecond();
        }

        return $times;
    }

    public function isOverlapping(): bool
    {
        if ($this->overlap_policy === OverlapPolicy::Allow) {
            return false;
        }

        return $this->project->activeRun() !== null;
    }
}
