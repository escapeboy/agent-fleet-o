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
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $team_id
 * @property string|null $credential_id
 * @property bool $is_platform
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property ToolType $type
 * @property string|null $subkind
 * @property ToolStatus $status
 * @property ToolRiskLevel|null $risk_level
 * @property array<string, mixed>|null $transport_config
 * @property array<string, mixed>|null $credentials
 * @property array<string, mixed>|null $tool_definitions
 * @property array<string, mixed>|null $server_capabilities
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $network_policy
 * @property Carbon|null $last_health_check
 * @property string|null $health_status
 * @property bool $result_as_answer
 * @property array<int, string>|null $tags
 */
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
        'subkind',
        'status',
        'risk_level',
        'transport_config',
        'credentials',
        'tool_definitions',
        'server_capabilities',
        'settings',
        'network_policy',
        'last_health_check',
        'health_status',
        'result_as_answer',
        'tags',
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
            'server_capabilities' => 'array',
            'settings' => 'array',
            'network_policy' => 'array',
            'result_as_answer' => 'boolean',
            'tags' => 'array',
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

    public function middlewareConfigs(): HasMany
    {
        return $this->hasMany(ToolMiddlewareConfig::class)->orderBy('priority');
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

    protected static function booted(): void
    {
        static::saving(function (Tool $tool): void {
            // Auto-tag Boruna MCP stdio tools so the resolver can match by
            // subkind instead of brittle JSONB substring search.
            if ($tool->subkind !== null) {
                return;
            }

            if ($tool->type !== ToolType::McpStdio) {
                return;
            }

            $command = (string) ($tool->transport_config['command'] ?? '');
            if ($command !== '' && stripos($command, 'boruna') !== false) {
                $tool->subkind = 'boruna';
            }
        });
    }
}
