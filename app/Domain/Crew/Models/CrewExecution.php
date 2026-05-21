<?php

namespace App\Domain\Crew\Models;

use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Enums\CrewExecutionTrustMode;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Models\Artifact;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $team_id
 * @property string $crew_id
 * @property string|null $experiment_id
 * @property string $goal
 * @property CrewExecutionStatus $status
 * @property array<int|string, mixed>|null $task_plan
 * @property array<string, mixed>|null $final_output
 * @property array<string, mixed>|null $config_snapshot
 * @property float|null $quality_score
 * @property int $coordinator_iterations
 * @property int $total_cost_credits
 * @property int|null $duration_ms
 * @property string|null $error_message
 * @property array<string, mixed>|null $error_metadata
 * @property int|null $delegation_depth
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property array<string, mixed>|null $quality_dimensions
 * @property CrewExecutionTrustMode|null $trust_mode
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Crew $crew
 * @property-read Experiment|null $experiment
 * @property-read Collection<int, CrewTaskExecution> $taskExecutions
 * @property-read Collection<int, CrewChatMessage> $chatMessages
 * @property-read Collection<int, Artifact> $artifacts
 */
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
        'error_metadata',
        'delegation_depth',
        'started_at',
        'completed_at',
        'quality_dimensions',
        'trust_mode',
    ];

    protected function casts(): array
    {
        return [
            'status' => CrewExecutionStatus::class,
            'task_plan' => 'array',
            'final_output' => 'array',
            'config_snapshot' => 'array',
            'quality_score' => 'float',
            'quality_dimensions' => 'array',
            'coordinator_iterations' => 'integer',
            'total_cost_credits' => 'integer',
            'duration_ms' => 'integer',
            'delegation_depth' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'error_metadata' => 'array',
            'trust_mode' => CrewExecutionTrustMode::class,
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

    /** @return HasMany<CrewTaskExecution, $this> */
    public function taskExecutions(): HasMany
    {
        return $this->hasMany(CrewTaskExecution::class)->orderBy('sort_order');
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(CrewChatMessage::class)->orderBy('round')->orderBy('created_at');
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

    /**
     * Derive the originating user for this run. Required by AiRequestDTO::userId
     * so claude-code-vps gateways can find the user via User::find(). Prefers
     * the linked experiment's owner; falls back to the team's owner for
     * standalone crew runs.
     */
    public function resolveUserId(): ?string
    {
        if ($this->experiment_id) {
            $userId = Experiment::withoutGlobalScopes()
                ->where('id', $this->experiment_id)
                ->value('user_id');

            if ($userId) {
                return $userId;
            }
        }

        return Team::withoutGlobalScopes()
            ->where('id', $this->team_id)
            ->value('owner_id');
    }
}
