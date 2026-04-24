<?php

namespace App\Domain\Crew\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\Models\ExternalAgent;
use App\Domain\Crew\Enums\CrewMemberRole;
use Database\Factories\Domain\Crew\CrewMemberFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrewMember extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory()
    {
        return CrewMemberFactory::new();
    }

    protected $fillable = [
        'crew_id',
        'agent_id',
        'external_agent_id',
        'member_kind',
        'role',
        'sort_order',
        'config',
        'context_scope',
    ];

    protected function casts(): array
    {
        return [
            'role' => CrewMemberRole::class,
            'config' => 'array',
            'context_scope' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function crew(): BelongsTo
    {
        return $this->belongsTo(Crew::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function externalAgent(): BelongsTo
    {
        return $this->belongsTo(ExternalAgent::class);
    }

    public function isExternal(): bool
    {
        return ($this->member_kind ?? 'internal') === 'external' || $this->external_agent_id !== null;
    }

    /**
     * Tool IDs this crew member is allowed to use (BroodMind worker permission template).
     * Empty array means no restriction — all agent tools are available.
     *
     * @return string[]
     */
    public function allowedToolIds(): array
    {
        return (array) ($this->config['tool_allowlist'] ?? []);
    }

    /**
     * Structured constraints for this crew member's execution context.
     *
     * @return array<string, mixed>
     */
    public function constraints(): array
    {
        return (array) ($this->config['constraints'] ?? []);
    }

    /**
     * Tool name allowlist for this crew member.
     * null means unrestricted — all agent tools are available.
     * Non-empty array restricts execution to only tools whose name is in the list.
     *
     * @return string[]|null
     */
    public function getToolAllowlistAttribute(): ?array
    {
        $list = $this->config['tool_allowlist'] ?? null;
        if ($list === null || $list === []) {
            return null;
        }

        return (array) $list;
    }

    /**
     * Maximum number of LLM tool-call steps allowed for this crew member.
     * null means use the agent's default tier configuration.
     */
    public function getMaxStepsAttribute(): ?int
    {
        $value = $this->config['max_steps'] ?? null;

        return $value !== null ? (int) $value : null;
    }

    /**
     * Maximum credits this crew member may spend per execution.
     * null means no per-member credit cap (agent budget applies).
     */
    public function getMaxCreditsAttribute(): ?int
    {
        $value = $this->config['max_credits'] ?? null;

        return $value !== null ? (int) $value : null;
    }
}
