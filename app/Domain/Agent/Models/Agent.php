<?php

namespace App\Domain\Agent\Models;

use App\Domain\Agent\Enums\AgentEnvironment;
use App\Domain\Agent\Enums\AgentReasoningStrategy;
use App\Domain\Agent\Enums\AgentScope;
use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Evolution\Models\EvolutionProposal;
use App\Domain\Knowledge\Models\KnowledgeBase;
use App\Domain\Shared\Enums\DataClassification;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Shared\Traits\HasPluginMeta;
use App\Domain\Skill\Models\Skill;
use App\Domain\Tool\Models\Tool;
use App\Infrastructure\AI\Models\CircuitBreakerState;
use App\Infrastructure\AI\Models\LlmRequestLog;
use App\Models\User;
use Database\Factories\Domain\Agent\AgentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    use BelongsToTeam, HasFactory, HasPluginMeta, HasUuids, SoftDeletes;

    /**
     * Text fields to be scanned for accidentally embedded secrets.
     *
     * @var array<int, string>
     */
    public array $scannableFields = ['role', 'goal', 'backstory'];

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
        'output_schema',
        'output_schema_max_retries',
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
        'knowledge_base_id',
        'risk_score',
        'risk_profile',
        'risk_profile_updated_at',
        'meta',
        'heartbeat_definition',
        'data_classification',
        'sandbox_profile',
        'tool_profile',
        'environment',
        'system_prompt_template',
        'reasoning_strategy',
        'scope',
        'owner_user_id',
        'chat_protocol_enabled',
        'chat_protocol_visibility',
        'chat_protocol_slug',
        'chat_protocol_config',
        'chat_protocol_secret',
    ];

    protected function casts(): array
    {
        return [
            'status' => AgentStatus::class,
            'chat_protocol_enabled' => 'boolean',
            'chat_protocol_visibility' => \App\Domain\AgentChatProtocol\Enums\AgentChatVisibility::class,
            'chat_protocol_config' => 'array',
            'reasoning_strategy' => AgentReasoningStrategy::class,
            'meta' => 'array',
            'personality' => 'array',
            'config' => 'array',
            'capabilities' => 'array',
            'constraints' => 'array',
            'output_schema' => 'array',
            'output_schema_max_retries' => 'integer',
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
            'heartbeat_definition' => 'array',
            'data_classification' => DataClassification::class,
            'sandbox_profile' => 'array',
            'system_prompt_template' => 'array',
            'scope' => AgentScope::class,
            'environment' => AgentEnvironment::class,
        ];
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }

    public function knowledgeBases(): BelongsToMany
    {
        return $this->belongsToMany(KnowledgeBase::class, 'agent_knowledge_base')
            ->withTimestamps();
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
            ->withPivot('priority', 'overrides', 'approval_mode', 'approval_timeout_minutes', 'approval_timeout_action')
            ->withTimestamps()
            ->orderByPivot('priority');
    }

    public function hooks(): HasMany
    {
        return $this->hasMany(AgentHook::class)->orderBy('priority');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(AgentExecution::class)->orderByDesc('created_at');
    }

    public function evolutionProposals(): HasMany
    {
        return $this->hasMany(EvolutionProposal::class)->orderByDesc('created_at');
    }

    public function configRevisions(): HasMany
    {
        return $this->hasMany(AgentConfigRevision::class)->orderByDesc('created_at');
    }

    public function runtimeState(): HasOne
    {
        return $this->hasOne(AgentRuntimeState::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * Scope to agents visible to a given user.
     * Team agents are visible to all team members.
     * Personal agents are visible to the owner and team admins/owners.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function ($q) use ($user) {
            $q->where('scope', AgentScope::Team->value)
                ->orWhere(function ($q2) use ($user) {
                    $q2->where('scope', AgentScope::Personal->value)
                        ->where(function ($q3) use ($user) {
                            $q3->where('owner_user_id', $user->id)
                                ->orWhereHas('team', function ($teamQ) use ($user) {
                                    $teamQ->whereHas('users', function ($uQ) use ($user) {
                                        $uQ->where('users.id', $user->id)
                                            ->whereIn('team_user.role', ['owner', 'admin']);
                                    });
                                });
                        });
                });
        });
    }

    public function scopeNotChatbotAgent($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('config->is_chatbot_agent')
                ->orWhere('config->is_chatbot_agent', false);
        });
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
