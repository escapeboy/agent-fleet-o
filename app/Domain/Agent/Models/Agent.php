<?php

namespace App\Domain\Agent\Models;

use App\Domain\Agent\Enums\AgentEnvironment;
use App\Domain\Agent\Enums\AgentReasoningStrategy;
use App\Domain\Agent\Enums\AgentScope;
use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Enums\ToolPermissionLevel;
use App\Domain\AgentChatProtocol\Enums\AgentChatVisibility;
use App\Domain\Evolution\Models\EvolutionProposal;
use App\Domain\Knowledge\Models\KnowledgeBase;
use App\Domain\Shared\Enums\DataClassification;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Shared\Traits\HasPluginMeta;
use App\Domain\Skill\Models\Skill;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Models\Toolset;
use App\Infrastructure\AI\Models\CircuitBreakerState;
use App\Infrastructure\AI\Models\LlmRequestLog;
use App\Models\User;
use Database\Factories\Domain\Agent\AgentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
 * @property string $id
 * @property string|null $team_id
 * @property string $name
 * @property string $slug
 * @property string|null $role
 * @property string|null $goal
 * @property string|null $backstory
 * @property array<string, mixed>|null $personality
 * @property string $provider
 * @property string $model
 * @property array<string, mixed>|null $output_schema
 * @property int|null $output_schema_max_retries
 * @property AgentStatus $status
 * @property array<string, mixed>|null $config
 * @property array<string, mixed>|null $capabilities
 * @property array<string, mixed>|null $constraints
 * @property int|null $budget_cap_credits
 * @property int $budget_spent_credits
 * @property int|null $max_credits_per_call
 * @property bool $evaluation_enabled
 * @property float $evaluation_sample_rate
 * @property string|null $evaluation_model
 * @property array<string, mixed>|null $evaluation_criteria
 * @property int $cost_per_1k_input
 * @property int $cost_per_1k_output
 * @property Carbon|null $last_health_check
 * @property int $equivocation_count
 * @property Carbon|null $last_equivocated_at
 * @property string|null $knowledge_base_id
 * @property string|null $risk_score
 * @property array<string, mixed>|null $risk_profile
 * @property Carbon|null $risk_profile_updated_at
 * @property array<string, mixed>|null $meta
 * @property array<string, mixed>|null $heartbeat_definition
 * @property DataClassification|null $data_classification
 * @property array<string, mixed>|null $sandbox_profile
 * @property string|null $tool_profile
 * @property AgentEnvironment|null $environment
 * @property array<string, mixed>|null $system_prompt_template
 * @property AgentReasoningStrategy|null $reasoning_strategy
 * @property AgentScope|null $scope
 * @property string|null $owner_user_id
 * @property bool $chat_protocol_enabled
 * @property AgentChatVisibility|null $chat_protocol_visibility
 * @property string|null $chat_protocol_slug
 * @property array<string, mixed>|null $chat_protocol_config
 * @property string|null $chat_protocol_secret
 * @property array<string, mixed>|null $tool_deny_list
 * @property string|null $default_workflow_id
 * @property bool $strict_mode
 * @property ToolPermissionLevel|null $tool_permission_default
 * @property-read Team|null $team
 * @property-read Collection<int, Skill> $skills
 * @property-read Collection<int, Tool> $tools
 * @property-read Collection<int, Toolset> $toolsets
 * @property-read User|null $owner
 * @property-read AgentRuntimeState|null $runtimeState
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
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
        'max_credits_per_call',
        'evaluation_enabled',
        'evaluation_sample_rate',
        'evaluation_model',
        'evaluation_criteria',
        'cost_per_1k_input',
        'cost_per_1k_output',
        'last_health_check',
        'equivocation_count',
        'last_equivocated_at',
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
        'tool_deny_list',
        'default_workflow_id',
        'strict_mode',
        'tool_permission_default',
    ];

    protected function casts(): array
    {
        return [
            'status' => AgentStatus::class,
            'chat_protocol_enabled' => 'boolean',
            'chat_protocol_visibility' => AgentChatVisibility::class,
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
            'max_credits_per_call' => 'integer',
            'evaluation_enabled' => 'boolean',
            'evaluation_sample_rate' => 'float',
            'evaluation_criteria' => 'array',
            'last_health_check' => 'datetime',
            'last_equivocated_at' => 'datetime',
            'equivocation_count' => 'integer',
            'risk_score' => 'decimal:2',
            'risk_profile' => 'array',
            'risk_profile_updated_at' => 'datetime',
            'heartbeat_definition' => 'array',
            'data_classification' => DataClassification::class,
            'sandbox_profile' => 'array',
            'system_prompt_template' => 'array',
            'scope' => AgentScope::class,
            'environment' => AgentEnvironment::class,
            'tool_deny_list' => 'array',
            'strict_mode' => 'boolean',
            'tool_permission_default' => ToolPermissionLevel::class,
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

    public function toolsets(): BelongsToMany
    {
        return $this->belongsToMany(Toolset::class, 'agent_toolset')
            ->withPivot('priority', 'auto_select')
            ->withTimestamps();
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

    /**
     * Resolve the effective max-credits-per-call cap with precedence:
     * agent.max_credits_per_call → team.effectiveMaxCreditsPerCall() → config.
     * Returns null when uncapped.
     */
    public function effectiveMaxCreditsPerCall(?Team $team = null): ?int
    {
        if ($this->max_credits_per_call !== null) {
            return (int) $this->max_credits_per_call;
        }

        if ($team !== null) {
            return $team->effectiveMaxCreditsPerCall();
        }

        $configMax = config('llm_pricing.max_credits_per_call');

        return $configMax !== null ? (int) $configMax : null;
    }
}
