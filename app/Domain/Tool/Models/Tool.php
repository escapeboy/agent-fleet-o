<?php

namespace App\Domain\Tool\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentToolPivot;
use App\Domain\Credential\Models\Credential;
use App\Domain\Shared\Traits\BelongsToTeam;
use App\Domain\Tool\Enums\ToolRiskLevel;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Infrastructure\Encryption\Casts\TeamEncryptedArray;
use Database\Factories\Domain\Tool\ToolFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tool extends Model
{
    use BelongsToTeam, HasFactory, HasUuids, SoftDeletes;

    protected static function newFactory()
    {
        return ToolFactory::new();
    }

    protected $fillable = [
        'team_id',
        'credential_id',
        'is_platform',
        'name',
        'slug',
        'description',
        'type',
        'status',
        'risk_level',
        'transport_config',
        'credentials',
        'tool_definitions',
        'settings',
        'last_health_check',
        'health_status',
        'result_as_answer',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'is_platform' => 'boolean',
            'type' => ToolType::class,
            'status' => ToolStatus::class,
            'risk_level' => ToolRiskLevel::class,
            'transport_config' => 'array',
            'credentials' => TeamEncryptedArray::class,
            'tool_definitions' => 'array',
            'settings' => 'array',
            'result_as_answer' => 'boolean',
            'last_health_check' => 'datetime',
        ];
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'agent_tool')
            ->using(AgentToolPivot::class)
            ->withPivot('priority', 'overrides')
            ->withTimestamps();
    }

    public function activations(): HasMany
    {
        return $this->hasMany(TeamToolActivation::class);
    }

    public function activationFor(string $teamId): ?TeamToolActivation
    {
        /** @var TeamToolActivation|null */
        return $this->activations()->where('team_id', $teamId)->first();
    }

    public function isPlatformTool(): bool
    {
        return $this->is_platform === true || $this->team_id === null;
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
