<?php

namespace App\Domain\Agent\Models;

use App\Domain\Agent\Enums\ToolLockoutMatchMode;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\Domain\Agent\AgentToolLockoutFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reviewer-lockout (Squad borrow): a durable record that encodes a review
 * decision into runtime state. While active (released_at IS NULL), the
 * ToolCallGovernor blocks any mutating tool call whose target matches
 * `resource`. A null agent_id makes the lockout team-wide.
 *
 * @property string $id
 * @property string $team_id
 * @property string|null $agent_id
 * @property string $resource
 * @property ToolLockoutMatchMode $match_mode
 * @property string|null $reason
 * @property string|null $locked_by
 * @property CarbonInterface|null $released_at
 * @property CarbonInterface|null $created_at
 */
class AgentToolLockout extends Model
{
    use BelongsToTeam, HasFactory, HasUuids;

    protected $fillable = [
        'team_id',
        'agent_id',
        'resource',
        'match_mode',
        'reason',
        'locked_by',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'match_mode' => ToolLockoutMatchMode::class,
            'released_at' => 'datetime',
        ];
    }

    protected static function newFactory(): AgentToolLockoutFactory
    {
        return AgentToolLockoutFactory::new();
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('released_at');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function isActive(): bool
    {
        return $this->released_at === null;
    }

    /**
     * Does this lockout block a tool call targeting any of the given candidate
     * strings (path, command, tool name)?
     *
     * @param  array<int, string>  $candidates
     */
    public function blocks(array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== '' && $this->match_mode->matches($candidate, $this->resource)) {
                return true;
            }
        }

        return false;
    }
}
