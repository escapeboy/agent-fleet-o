<?php

namespace App\Domain\Agent\Models;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Evolution\Models\EvolutionProposal;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Skill\Models\Skill;
use App\Domain\Tool\Models\Tool;
use App\Infrastructure\AI\Models\CircuitBreakerState;
use App\Infrastructure\AI\Models\LlmRequestLog;
use Database\Factories\Domain\Agent\AgentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string|null $team_id
 * @property string $name
 * @property string|null $role
 * @property string|null $goal
 * @property string|null $backstory
 * @property array|null $personality
 * @property string $provider
 * @property string $model
 * @property AgentStatus $status
 * @property array|null $config
 * @property array|null $capabilities
 * @property array|null $constraints
 * @property int|null $budget_cap_credits
 * @property int $budget_spent_credits
 * @property int $cost_per_1k_input
 * @property int $cost_per_1k_output
 * @property Carbon|null $last_health_check
 */
class Agent extends Model
{
    use BelongsToTeam, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'team_id',
        'name',
        'slug',
        'role',
        'goal',
        'backstory',
        'personality',
        'provider',
        'model',
        'status',
        'config',
        'capabilities',
        'constraints',
        'budget_cap_credits',
        'budget_spent_credits',
        'evaluation_enabled',
        'evaluation_sample_rate',
        'evaluation_model',
        'evaluation_criteria',
        'cost_per_1k_input',
        'cost_per_1k_output',
        'last_health_check',
        'risk_score',
        'risk_profile',
        'risk_profile_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AgentStatus::class,
            'personality' => 'array',
            'config' => 'array',
            'capabilities' => 'array',
            'constraints' => 'array',
            'cost_per_1k_input' => 'integer',
            'cost_per_1k_output' => 'integer',
            'budget_cap_credits' => 'integer',
            'budget_spent_credits' => 'integer',
            'evaluation_enabled' => 'boolean',
            'evaluation_sample_rate' => 'float',
            'evaluation_criteria' => 'array',
            'last_health_check' => 'datetime',
            'risk_score' => 'decimal:2',
            'risk_profile' => 'array',
            'risk_profile_updated_at' => 'datetime',
        ];
    }

    public function aiRuns(): HasMany
    {
        return $this->hasMany(AiRun::class);
    }

    public function llmRequestLogs(): HasMany
    {
        return $this->hasMany(LlmRequestLog::class);
    }

    public function circuitBreakerState(): HasOne
    {
        return $this->hasOne(CircuitBreakerState::class);
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'agent_skill')
            ->using(AgentSkillPivot::class)
            ->withPivot('priority', 'overrides')
            ->withTimestamps()
            ->orderByPivot('priority');
    }

    public function tools(): BelongsToMany
    {
        return $this->belongsToMany(Tool::class, 'agent_tool')
            ->using(AgentToolPivot::class)
            ->withPivot('priority', 'overrides')
            ->withTimestamps()
            ->orderByPivot('priority');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(AgentExecution::class)->orderByDesc('created_at');
    }

    public function evolutionProposals(): HasMany
    {
        return $this->hasMany(EvolutionProposal::class)->orderByDesc('created_at');
    }

    protected static function newFactory()
    {
        return AgentFactory::new();
    }

    public function hasBudgetRemaining(): bool
    {
        if ($this->budget_cap_credits === null) {
            return true;
        }

        return $this->budget_spent_credits < $this->budget_cap_credits;
    }

    public function budgetRemainingCredits(): ?int
    {
        if ($this->budget_cap_credits === null) {
            return null;
        }

        return max(0, $this->budget_cap_credits - $this->budget_spent_credits);
    }
}
