<?php

namespace App\Domain\Tool\Models;

use App\Domain\Tool\Enums\RegistryTrustLevel;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Platform-wide curated catalog of approved MCP servers. Distinct from the
 * Smithery public browse surface (App\Domain\Tool\Services\McpRegistryClient).
 *
 * Entries here are added by an administrator and become installable for any
 * team. Tools installed from a registry entry carry a registry_server_id FK
 * back to the source — deleting the registry entry leaves the installed
 * Tool rows intact (nullOnDelete).
 *
 * Not team-scoped: this is a platform-tier concern.
 *
 * Enum and complex-cast @property hints — see Skill.php for context.
 *
 * @property RegistryTrustLevel|null $trust_level
 * @property bool $is_active
 * @property array<string, mixed> $connection
 * @property array<int, string>|null $tool_allowlist
 * @property array<string, mixed>|null $policy_rules
 */
class McpServerRegistry extends Model
{
    use HasUuids;

    protected $table = 'mcp_server_registry';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'transport',
        'connection',
        'trust_level',
        'is_active',
        'tool_allowlist',
        'policy_rules',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'connection' => 'array',
            'tool_allowlist' => 'array',
            'policy_rules' => 'array',
            'is_active' => 'boolean',
            'trust_level' => RegistryTrustLevel::class,
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function installedTools(): HasMany
    {
        return $this->hasMany(Tool::class, 'registry_server_id');
    }
}
