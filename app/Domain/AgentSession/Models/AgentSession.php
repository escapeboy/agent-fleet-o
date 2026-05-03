<?php

namespace App\Domain\AgentSession\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Append-only session over an agent's long-running work. Decouples the
 * agent's durable state from any single sandbox/container — wake() can
 * reconstitute a SessionContext after a sandbox crash.
 *
 * Sourced from research_long_running_agents_2026-05-03 Gap E.
 *
 * @property string $id
 * @property string $team_id
 * @property string|null $agent_id
 * @property string|null $experiment_id
 * @property string|null $crew_execution_id
 * @property string|null $user_id
 * @property AgentSessionStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property Carbon|null $last_heartbeat_at
 * @property array<string, mixed>|null $workspace_contract_snapshot
 * @property string|null $last_known_sandbox_id
 * @property array<string, mixed>|null $metadata
 */
class AgentSession extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'agent_id',
        'experiment_id',
        'crew_execution_id',
        'user_id',
        'status',
        'started_at',
        'ended_at',
        'last_heartbeat_at',
        'workspace_contract_snapshot',
        'last_known_sandbox_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => AgentSessionStatus::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'workspace_contract_snapshot' => 'array',
            'metadata' => 'array',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(Experiment::class);
    }

    public function crewExecution(): BelongsTo
    {
        return $this->belongsTo(CrewExecution::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<AgentSessionEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(AgentSessionEvent::class, 'session_id')->orderBy('seq');
    }

    /**
     * Last sequence number written for this session, 0 when empty.
     */
    public function lastSeq(): int
    {
        return (int) $this->events()->max('seq');
    }
}
