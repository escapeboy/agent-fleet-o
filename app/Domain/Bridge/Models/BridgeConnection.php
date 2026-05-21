<?php

namespace App\Domain\Bridge\Models;

use App\Domain\Bridge\Enums\BridgeConnectionStatus;
use App\Domain\Shared\Traits\BelongsToTeam;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $team_id
 * @property string $session_id
 * @property string|null $label
 * @property int $priority
 * @property BridgeConnectionStatus $status
 * @property string|null $bridge_version
 * @property array<string, mixed>|null $endpoints
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $connected_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $disconnected_at
 * @property string|null $endpoint_url
 * @property string|null $endpoint_secret
 * @property string|null $tunnel_provider
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
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
        'endpoint_url',
        'endpoint_secret',
        'tunnel_provider',
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

    /**
     * Whether this connection uses the HTTP tunnel mode (endpoint_url configured).
     * HTTP mode: FleetQ calls the tunnel URL directly via HTTP SSE.
     */
    public function isHttpMode(): bool
    {
        return ! empty($this->endpoint_url);
    }

    /**
     * Whether this connection uses the legacy WebSocket relay mode.
     */
    public function isRelayMode(): bool
    {
        return ! $this->isHttpMode();
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
