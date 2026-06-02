<?php

namespace App\Domain\Agent\Models;

use App\Domain\Agent\Enums\AgentPolicyStatus;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Traits\BelongsToTeam;
use Carbon\CarbonInterface;
use Database\Factories\Domain\Agent\AgentPolicyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Versioned authority boundary for an agent (or the team default when
 * agent_id is null). The row is a current-pointer; the authoritative rules
 * live on the pinned AgentPolicyVersion so a policy change is an immutable,
 * rollback-able event rather than an in-place mutation.
 *
 * @property string $id
 * @property string|null $team_id
 * @property string|null $agent_id
 * @property string $name
 * @property AgentPolicyStatus $status
 * @property bool $enabled
 * @property string|null $current_version_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read AgentPolicyVersion|null $currentVersion
 */
class AgentPolicy extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected $fillable = [
        'team_id',
        'agent_id',
        'name',
        'status',
        'enabled',
        'current_version_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => AgentPolicyStatus::class,
            'enabled' => 'boolean',
        ];
    }

    protected static function newFactory(): AgentPolicyFactory
    {
        return AgentPolicyFactory::new();
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(AgentPolicyVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AgentPolicyVersion::class);
    }

    public function isActive(): bool
    {
        return $this->status === AgentPolicyStatus::Active;
    }
}
