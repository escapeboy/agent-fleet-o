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

    /**
     * Model ids reported by this connection's online LLM endpoints, each listed once,
     * in the order they first appear.
     *
     * @return list<string>
     */
    public function onlineLlmModels(): array
    {
        $models = [];

        foreach ($this->llmEndpoints() as $ep) {
            if (! ($ep['online'] ?? false) || ! is_array($ep['models'] ?? null)) {
                continue;
            }

            foreach ($ep['models'] as $model) {
                if (is_string($model) && $model !== '' && ! in_array($model, $models, true)) {
                    $models[] = $model;
                }
            }
        }

        return $models;
    }

    /**
     * Whether any online LLM endpoint reports no model list (missing, non-array or empty).
     * Such endpoints may serve any model, so a model check cannot be trusted.
     */
    public function hasOnlineLlmEndpointWithoutModels(): bool
    {
        foreach ($this->llmEndpoints() as $ep) {
            if (! ($ep['online'] ?? false)) {
                continue;
            }

            $models = $ep['models'] ?? null;
            if (! is_array($models) || $models === []) {
                return true;
            }
        }

        return false;
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
