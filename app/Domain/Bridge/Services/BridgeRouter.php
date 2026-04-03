<?php

namespace App\Domain\Bridge\Services;

use App\Domain\Bridge\Enums\BridgeConnectionStatus;
use App\Domain\Bridge\Models\BridgeConnection;
use App\Domain\Shared\Models\Team;
use Illuminate\Support\Collection;

class BridgeRouter
{
    /**
     * Find the best bridge connection for a given agent key.
     */
    public function resolveForAgent(string $teamId, string $agentKey): ?BridgeConnection
    {
        $candidates = $this->activeBridgesWithAgent($teamId, $agentKey);

        if ($candidates->isEmpty()) {
            return null;
        }

        $settings = $this->bridgeSettings($teamId);
        $mode = $settings['routing_mode'] ?? 'auto';

        return match ($mode) {
            'per_agent' => $this->resolvePerAgent($candidates, $agentKey, $settings),
            'prefer' => $this->resolvePreferred($candidates, $settings),
            default => $this->resolveBest($candidates),
        };
    }

    /**
     * Find the best bridge connection that has a specific MCP server available.
     */
    public function resolveForMcpServer(string $teamId, string $serverName): ?BridgeConnection
    {
        return $this->activeConnections($teamId)
            ->first(fn (BridgeConnection $c) => collect($c->mcpServers())
                ->contains(fn (array $s) => ($s['name'] ?? '') === $serverName));
    }

    /**
     * Get ALL available agents across all active bridges for a team.
     *
     * Each agent entry is enriched with bridge_id and bridge_label.
     * When the same agent key appears on multiple bridges, all instances are returned.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function allAvailableAgents(string $teamId): Collection
    {
        return $this->activeConnections($teamId)
            ->flatMap(fn (BridgeConnection $c) => collect($c->agents())
                ->filter(fn (array $a) => $a['found'] ?? false)
                ->map(fn (array $a) => array_merge($a, [
                    'bridge_id' => $c->id,
                    'bridge_label' => $c->label ?? $c->ip_address,
                ])))
            ->values();
    }

    /**
     * Get deduplicated agents (unique by key, prefer highest-priority bridge).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function uniqueAvailableAgents(string $teamId): Collection
    {
        return $this->allAvailableAgents($teamId)->unique('key')->values();
    }

    /**
     * Get all active bridge connections for a team.
     *
     * Includes bridges that disconnected within the grace period (60s) so that
     * brief network blips don't make agents invisible. Connected bridges are
     * always preferred over recently-disconnected ones.
     *
     * @return Collection<int, BridgeConnection>
     */
    public function activeConnections(string $teamId): Collection
    {
        return BridgeConnection::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where(function ($q) {
                $q->where('status', BridgeConnectionStatus::Connected->value)
                    ->orWhere(function ($q2) {
                        // Include recently-disconnected bridges as fallback
                        $q2->whereIn('status', [
                            BridgeConnectionStatus::Disconnected->value,
                            BridgeConnectionStatus::Reconnecting->value,
                        ])->where('last_seen_at', '>=', now()->subSeconds(60));
                    });
            })
            // Connected first, then by priority, then most recent
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [BridgeConnectionStatus::Connected->value])
            ->orderByDesc('priority')
            ->orderByDesc('connected_at')
            ->get();
    }

    /**
     * Get all bridge connections for a team (active + recent disconnected).
     *
     * @return Collection<int, BridgeConnection>
     */
    public function allConnections(string $teamId): Collection
    {
        return BridgeConnection::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->orderByRaw("CASE WHEN status = 'connected' THEN 0 ELSE 1 END")
            ->orderByDesc('priority')
            ->orderByDesc('connected_at')
            ->limit(20)
            ->get();
    }

    private function activeBridgesWithAgent(string $teamId, string $agentKey): Collection
    {
        return $this->activeConnections($teamId)
            ->filter(fn (BridgeConnection $c) => collect($c->agents())
                ->contains(fn (array $a) => ($a['key'] ?? '') === $agentKey && ($a['found'] ?? false)));
    }

    private function resolvePerAgent(Collection $candidates, string $agentKey, array $settings): BridgeConnection
    {
        $preferredId = $settings['agent_routing'][$agentKey] ?? null;

        if ($preferredId && $match = $candidates->firstWhere('id', $preferredId)) {
            return $match;
        }

        return $this->resolveBest($candidates);
    }

    private function resolvePreferred(Collection $candidates, array $settings): BridgeConnection
    {
        $preferredId = $settings['preferred_bridge_id'] ?? null;

        if ($preferredId && $match = $candidates->firstWhere('id', $preferredId)) {
            return $match;
        }

        return $this->resolveBest($candidates);
    }

    private function resolveBest(Collection $candidates): BridgeConnection
    {
        // Already sorted by priority desc, connected_at desc from activeConnections()
        return $candidates->first();
    }

    private function bridgeSettings(string $teamId): array
    {
        try {
            $team = Team::withoutGlobalScopes()->find($teamId);

            return $team?->settings['bridge'] ?? [];
        } catch (\Throwable) {
            return [];
        }
    }
}
