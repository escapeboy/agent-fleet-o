<?php

namespace App\Domain\Bridge\Models;

use App\Domain\Bridge\Enums\BridgeConnectionStatus;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BridgeConnection extends Model
{
    use BelongsToTeam, HasUuids;

    protected $fillable = [
        'team_id',
        'session_id',
        'label',
        'priority',
        'status',
        'bridge_version',
        'endpoints',
        'ip_address',
        'user_agent',
        'connected_at',
        'last_seen_at',
        'disconnected_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BridgeConnectionStatus::class,
            'endpoints' => 'array',
            'connected_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'disconnected_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === BridgeConnectionStatus::Connected;
    }

    /** @return list<array<string, mixed>> */
    public function llmEndpoints(): array
    {
        /** @var list<mixed> $items */
        $items = (array) ($this->endpoints['llm_endpoints'] ?? []);

        return array_values(array_filter($items, 'is_array'));
    }

    /** @return list<array<string, mixed>> */
    public function agents(): array
    {
        /** @var list<mixed> $items */
        $items = (array) ($this->endpoints['agents'] ?? []);

        return array_values(array_filter($items, 'is_array'));
    }

    /** @return list<array<string, mixed>> */
    public function mcpServers(): array
    {
        /** @var list<mixed> $items */
        $items = (array) ($this->endpoints['mcp_servers'] ?? []);

        return array_values(array_filter($items, 'is_array'));
    }

    /** @return list<array<string, mixed>> */
    public function ideMcpConfigs(): array
    {
        /** @var list<mixed> $items */
        $items = (array) ($this->endpoints['ide_mcp_configs'] ?? []);

        return array_values(array_filter($items, 'is_array'));
    }

    public function onlineLlmCount(): int
    {
        return count(array_filter($this->llmEndpoints(), fn ($ep) => $ep['online'] ?? false));
    }

    public function foundAgentCount(): int
    {
        return count(array_filter($this->agents(), fn ($a) => $a['found'] ?? false));
    }

    public function scopeActive($query)
    {
        return $query->where('status', BridgeConnectionStatus::Connected->value);
    }
}
