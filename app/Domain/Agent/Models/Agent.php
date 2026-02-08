<?php

namespace App\Domain\Agent\Models;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Skill\Models\Skill;
use App\Infrastructure\AI\Models\CircuitBreakerState;
use App\Infrastructure\AI\Models\LlmRequestLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        'provider',
        'model',
        'status',
        'config',
        'capabilities',
        'constraints',
        'budget_cap_credits',
        'budget_spent_credits',
        'cost_per_1k_input',
        'cost_per_1k_output',
        'last_health_check',
    ];

    protected function casts(): array
    {
        return [
            'status' => AgentStatus::class,
            'config' => 'array',
            'capabilities' => 'array',
            'constraints' => 'array',
            'cost_per_1k_input' => 'integer',
            'cost_per_1k_output' => 'integer',
            'budget_cap_credits' => 'integer',
            'budget_spent_credits' => 'integer',
            'last_health_check' => 'datetime',
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
            ->withPivot('priority', 'overrides')
            ->withTimestamps()
            ->orderByPivot('priority');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(AgentExecution::class)->orderByDesc('created_at');
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
