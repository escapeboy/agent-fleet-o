<?php

namespace App\Domain\Agent\Models;

use App\Domain\Agent\Enums\AgentHookPosition;
use App\Domain\Agent\Enums\AgentHookType;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * User-configurable hook attached to an agent lifecycle position.
 *
 * When agent_id is null, the hook applies to ALL agents in the team (class-level).
 * When agent_id is set, it applies only to that specific agent (instance-level).
 *
 * @property string $team_id
 * @property string|null $agent_id
 * @property string $name
 * @property AgentHookPosition $position
 * @property AgentHookType $type
 * @property array $config
 * @property int $priority
 * @property bool $enabled
 */
class AgentHook extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'agent_id',
        'name',
        'position',
        'type',
        'config',
        'priority',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'position' => AgentHookPosition::class,
            'type' => AgentHookType::class,
            'config' => 'array',
            'priority' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function isClassLevel(): bool
    {
        return $this->agent_id === null;
    }

    public function isInstanceLevel(): bool
    {
        return $this->agent_id !== null;
    }
}
