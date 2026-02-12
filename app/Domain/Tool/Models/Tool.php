<?php

namespace App\Domain\Tool\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tool extends Model
{
    use BelongsToTeam, HasUuids, SoftDeletes;

    protected $fillable = [
        'team_id',
        'name',
        'slug',
        'description',
        'type',
        'status',
        'transport_config',
        'credentials',
        'tool_definitions',
        'settings',
        'last_health_check',
        'health_status',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'type' => ToolType::class,
            'status' => ToolStatus::class,
            'transport_config' => 'array',
            'credentials' => 'encrypted:array',
            'tool_definitions' => 'array',
            'settings' => 'array',
            'last_health_check' => 'datetime',
        ];
    }

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'agent_tool')
            ->withPivot('priority', 'overrides')
            ->withTimestamps();
    }

    public function isAvailable(): bool
    {
        return $this->status === ToolStatus::Active
            && $this->health_status !== 'unreachable';
    }

    public function isMcp(): bool
    {
        return $this->type->isMcp();
    }

    public function isBuiltIn(): bool
    {
        return $this->type === ToolType::BuiltIn;
    }

    public function functionCount(): int
    {
        return count($this->tool_definitions ?? []);
    }
}
