<?php

namespace App\Domain\Skill\Services;

use App\Domain\Skill\Models\Skill;
use App\Domain\Tool\Models\Tool;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Caches successful Boruna script results.
 *
 * Boruna's `.ax` runtime is deterministic by design: for fixed
 * (binary, script, input, policy) the output is identical. We exploit that to
 * skip the MCP stdio round-trip on repeated calls.
 *
 * Cache key components:
 *   - team_id              tenant isolation (must be in the key, not just scope)
 *   - tool_id              binary identity at the tool registration level
 *   - tool.updated_at      proxy for binary upgrades — when the user re-points
 *                          the Tool at a new binary path/args we want a fresh key
 *   - script               the .ax source
 *   - input                JSON-serialised script arguments
 *   - policy               'allow-all' | 'deny-all'
 *
 * Note: this is a conservative key. It will produce false misses across teams
 * even when the script + binary are identical (intentional — tenant isolation).
 * It will also miss when a Boruna binary upgrade is invisible to us (e.g. the
 * user updated /usr/local/bin/boruna without bumping the Tool record). Once
 * upstream Boruna ships a versioned `capability_set_hash` (see
 * claudedocs/boruna_upstream_feedback_2026-04-25.md, ask #3) we can replace
 * `tool.updated_at` with a true binary-content hash and lift the conservative
 * miss rate.
 */
class BorunaResultCache
{
    /** 24h — long enough to amortise repeat agent loops, short enough that
     *  pathological staleness self-heals without operator intervention. */
    private const TTL_SECONDS = 86400;

    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * @param  string|array<string,mixed>  $policy  Either the legacy shorthand or
     *                                              a Boruna v0.2.0 Capability Policy.
     */
    public function get(Tool $tool, Skill $skill, array $input, string|array $policy): ?array
    {
        $cached = $this->cache->get($this->key($tool, $skill, $input, $policy));

        return is_array($cached) ? $cached : null;
    }

    /**
     * @param  string|array<string,mixed>  $policy
     */
    public function put(Tool $tool, Skill $skill, array $input, string|array $policy, array $output): void
    {
        $this->cache->put(
            $this->key($tool, $skill, $input, $policy),
            $output,
            self::TTL_SECONDS,
        );
    }

    /**
     * @param  string|array<string,mixed>  $policy
     */
    private function key(Tool $tool, Skill $skill, array $input, string|array $policy): string
    {
        $script = (string) ($skill->configuration['script'] ?? '');

        // Serialize structured policies so two different shapes can't share a
        // cache slot. JSON-encoded arrays get a leading "{" / "[" which
        // can never collide with the literal strings 'allow-all' / 'deny-all'.
        $policyMaterial = is_array($policy)
            ? (string) json_encode($policy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $policy;

        $material = implode('|', [
            (string) $tool->id,
            $tool->updated_at?->toISOString() ?? '',
            $script,
            (string) json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $policyMaterial,
        ]);

        return sprintf(
            'boruna:result:%s:%s:%s',
            (string) $tool->team_id,
            (string) $tool->id,
            hash('sha256', $material),
        );
    }
}
