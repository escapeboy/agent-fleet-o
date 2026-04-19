<?php

namespace App\Domain\Tool\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only log of tool search selection events.
 * Written by ResolveAgentToolsAction::mergeSearchedTools each time the
 * opt-in tool search feature surfaces matching tools for an agent run.
 *
 * @property string $id
 * @property string $team_id
 * @property string|null $agent_id
 * @property string|null $experiment_id
 * @property string $query
 * @property int $pool_size
 * @property int $matched_count
 * @property array $matched_slugs
 * @property array $matched_ids
 * @property Carbon $created_at
 */
class ToolSearchLog extends Model
{
    use BelongsToTeam, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'team_id',
        'agent_id',
        'experiment_id',
        'query',
        'pool_size',
        'matched_count',
        'matched_slugs',
        'matched_ids',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'matched_slugs' => 'array',
            'matched_ids' => 'array',
            'pool_size' => 'integer',
            'matched_count' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
