<?php

namespace App\Domain\Crew\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Crew\Enums\CrewMemberRole;
use App\Domain\Crew\Enums\CrewProcessType;
use App\Domain\Crew\Enums\CrewStatus;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Shared\Traits\HasPluginMeta;
use App\Models\User;
use Database\Factories\Domain\Crew\CrewFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Crew extends Model
{
    use BelongsToTeam, HasFactory, HasPluginMeta, HasUuids, SoftDeletes;

    protected static function newFactory()
    {
        return CrewFactory::new();
    }

    protected $fillable = [
        'team_id',
        'user_id',
        'coordinator_agent_id',
        'qa_agent_id',
        'name',
        'slug',
        'description',
        'process_type',
        'max_task_iterations',
        'quality_threshold',
        'status',
        'settings',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'process_type' => CrewProcessType::class,
            'status' => CrewStatus::class,
            'settings' => 'array',
            'max_task_iterations' => 'integer',
            'quality_threshold' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'coordinator_agent_id');
    }

    public function qaAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'qa_agent_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(CrewMember::class)->orderBy('sort_order');
    }

    public function workerMembers(): HasMany
    {
        return $this->hasMany(CrewMember::class)
            ->where('role', CrewMemberRole::Worker->value)
            ->orderBy('sort_order');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(CrewExecution::class)->orderByDesc('created_at');
    }

    public function hasWorkers(): bool
    {
        return $this->workerMembers()->exists();
    }

    public function isCoordinatorOnly(): bool
    {
        return ! $this->hasWorkers();
    }

    public function agentCount(): int
    {
        return 2 + $this->workerMembers()->count(); // coordinator + QA + workers
    }

    public function restructuringProposals(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class, 'team_id', 'team_id')
            ->whereJsonContains('context->type', 'crew_restructuring')
            ->whereRaw("context->>'crew_id' = ?", [$this->id ?? '']);
    }

    /**
     * How crew convergence is evaluated.
     * Stored in settings['convergence_mode']:
     *   any_validated     — complete when any task passes QA (default, legacy behaviour)
     *   all_validated     — complete only when ALL tasks pass QA
     *   threshold_ratio   — complete when min_validated_ratio fraction of tasks pass QA
     *   quality_gate      — complete when final synthesis quality_score >= quality_threshold
     */
    public function getConvergenceModeAttribute(): string
    {
        return $this->settings['convergence_mode'] ?? 'any_validated';
    }

    /**
     * Minimum fraction of tasks that must be validated when using threshold_ratio mode.
     * E.g. 0.8 = 80 % of tasks must pass QA before synthesis begins.
     */
    public function getMinValidatedRatioAttribute(): float
    {
        return (float) ($this->settings['min_validated_ratio'] ?? 1.0);
    }
}
