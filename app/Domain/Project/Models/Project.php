<?php

namespace App\Domain\Project\Models;

use App\Domain\Crew\Models\Crew;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Enums\ProjectType;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Workflow\Models\Workflow;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Project extends Model
{
    use BelongsToTeam, HasUuids;

    /**
     * Projects this project depends on (upstream).
     */
    public function dependencies(): HasMany
    {
        return $this->hasMany(ProjectDependency::class)->orderBy('sort_order');
    }

    /**
     * Projects that depend on this project (downstream).
     */
    public function dependents(): HasMany
    {
        return $this->hasMany(ProjectDependency::class, 'depends_on_id');
    }

    protected $fillable = [
        'team_id',
        'user_id',
        'title',
        'description',
        'type',
        'status',
        'paused_from_status',
        'goal',
        'crew_id',
        'workflow_id',
        'agent_config',
        'budget_config',
        'notification_config',
        'delivery_config',
        'settings',
        'allowed_tool_ids',
        'allowed_credential_ids',
        'total_runs',
        'successful_runs',
        'failed_runs',
        'total_spend_credits',
        'started_at',
        'paused_at',
        'completed_at',
        'last_run_at',
        'next_run_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => ProjectType::class,
            'status' => ProjectStatus::class,
            'agent_config' => 'array',
            'budget_config' => 'array',
            'notification_config' => 'array',
            'delivery_config' => 'array',
            'settings' => 'array',
            'allowed_tool_ids' => 'array',
            'allowed_credential_ids' => 'array',
            'total_runs' => 'integer',
            'successful_runs' => 'integer',
            'failed_runs' => 'integer',
            'total_spend_credits' => 'integer',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function crew(): BelongsTo
    {
        return $this->belongsTo(Crew::class);
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function schedule(): HasOne
    {
        return $this->hasOne(ProjectSchedule::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ProjectRun::class)->orderByDesc('run_number');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(ProjectMilestone::class)->orderBy('sort_order');
    }

    // Scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ProjectStatus::Active);
    }

    public function scopeContinuous(Builder $query): Builder
    {
        return $query->where('type', ProjectType::Continuous);
    }

    public function scopeOneShot(Builder $query): Builder
    {
        return $query->where('type', ProjectType::OneShot);
    }

    // Helpers

    public function latestRun(): ?ProjectRun
    {
        return $this->runs()->first();
    }

    public function activeRun(): ?ProjectRun
    {
        return $this->runs()
            ->whereIn('status', ['pending', 'running'])
            ->first();
    }

    public function successRate(): float
    {
        if ($this->total_runs === 0) {
            return 0;
        }

        return round(($this->successful_runs / $this->total_runs) * 100, 1);
    }

    public function consecutiveFailures(): int
    {
        return $this->runs()
            ->where('status', 'failed')
            ->orderByDesc('run_number')
            ->get()
            ->takeWhile(fn (ProjectRun $run) => $run->status->value === 'failed')
            ->count();
    }

    public function isOverBudget(string $period = 'daily'): bool
    {
        $cap = $this->budget_config[$period . '_cap'] ?? null;
        if (! $cap) {
            return false;
        }

        return $this->periodSpend($period) >= $cap;
    }

    public function periodSpend(string $period): int
    {
        $since = match ($period) {
            'daily' => now()->startOfDay(),
            'weekly' => now()->startOfWeek(),
            'monthly' => now()->startOfMonth(),
            default => now()->startOfDay(),
        };

        return $this->runs()
            ->where('created_at', '>=', $since)
            ->sum('spend_credits');
    }

    public function isContinuous(): bool
    {
        return $this->type === ProjectType::Continuous;
    }

    public function isOneShot(): bool
    {
        return $this->type === ProjectType::OneShot;
    }
}
