<?php

namespace App\Domain\Tool\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property array<int, string>|null $tool_ids
 * @property array<int, string>|null $tags
 * @property bool $is_platform
 * @property array<string, mixed>|null $browser_helpers
 * @property bool $browser_helpers_pending_review
 */
class Toolset extends Model
{
    use BelongsToTeam, HasUuids, SoftDeletes;

    protected $fillable = [
        'team_id',
        'name',
        'slug',
        'description',
        'tool_ids',
        'tags',
        'is_platform',
        'created_by',
        'browser_helpers',
        'browser_helpers_pending_review',
    ];

    protected function casts(): array
    {
        return [
            'tool_ids' => 'array',
            'tags' => 'array',
            'is_platform' => 'boolean',
            'browser_helpers' => 'array',
            'browser_helpers_pending_review' => 'boolean',
        ];
    }

    public function tools(): Collection
    {
        return Tool::whereIn('id', $this->tool_ids ?? [])->get();
    }

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'agent_toolset')
            ->withPivot('priority', 'auto_select')
            ->withTimestamps();
    }
}
