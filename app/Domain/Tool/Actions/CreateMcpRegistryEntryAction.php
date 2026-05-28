<?php

namespace App\Domain\Tool\Actions;

use App\Domain\Tool\Enums\RegistryTrustLevel;
use App\Domain\Tool\Models\McpServerRegistry;
use Illuminate\Support\Str;

class CreateMcpRegistryEntryAction
{
    /**
     * @param  array{name: string, description?: string|null, transport: string, connection: array<string, mixed>, trust_level?: string, tool_allowlist?: array<int, string>|null, policy_rules?: array<string, mixed>}  $attrs
     */
    public function execute(array $attrs, ?string $createdById = null): McpServerRegistry
    {
        $slug = Str::slug($attrs['name']);
        $base = $slug;
        $suffix = 1;

        while (McpServerRegistry::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return McpServerRegistry::create([
            'name' => $attrs['name'],
            'slug' => $slug,
            'description' => $attrs['description'] ?? null,
            'transport' => $attrs['transport'],
            'connection' => $attrs['connection'],
            'trust_level' => $attrs['trust_level'] ?? RegistryTrustLevel::Community->value,
            'is_active' => $attrs['is_active'] ?? true,
            'tool_allowlist' => $attrs['tool_allowlist'] ?? null,
            'policy_rules' => $attrs['policy_rules'] ?? [],
            'created_by' => $createdById,
        ]);
    }
}
