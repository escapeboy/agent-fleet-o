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

    public function llmEndpoints(): array
    {
        return array_values(array_filter($this->endpoints['llm_endpoints'] ?? [], 'is_array'));
    }

    public function agents(): array
    {
        return array_values(array_filter($this->endpoints['agents'] ?? [], 'is_array'));
    }

    public function mcpServers(): array
    {
        return array_values(array_filter($this->endpoints['mcp_servers'] ?? [], 'is_array'));
    }

    public function ideMcpConfigs(): array
    {
        return $this->endpoints['ide_mcp_configs'] ?? [];
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
