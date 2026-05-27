<?php

namespace App\Infrastructure\AI\Services;

use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\DTOs\AiRequestDTO;

/**
 * Builds the per-run egress allowlist for the secret-proxy daemon. Default-deny:
 * the list is exactly the upstreams the run legitimately needs (Anthropic + the
 * agent's attached MCP hosts) plus any team-configured extras. The daemon
 * enforces it; this service only assembles it at issue time.
 */
class EgressAllowlist
{
    /**
     * @param  array<int, string>  $upstreamHosts
     * @return array<int, string>
     */
    public function forRun(AiRequestDTO $request, array $upstreamHosts): array
    {
        $hosts = [];
        foreach ($upstreamHosts as $host) {
            if ($host !== '') {
                $hosts[] = strtolower($host);
            }
        }

        $team = $request->teamId ? Team::withoutGlobalScopes()->find($request->teamId) : null;
        $extra = $team?->settings['egress_allowlist'] ?? [];
        if (is_array($extra)) {
            foreach ($extra as $host) {
                if (is_string($host) && $host !== '') {
                    $hosts[] = strtolower($host);
                }
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * @param  array<int, string>  $allowlist
     */
    public function allows(array $allowlist, string $host): bool
    {
        return in_array(strtolower($host), $allowlist, true);
    }
}
