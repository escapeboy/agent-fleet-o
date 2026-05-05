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
 *   - team_id                tenant isolation (must be in the key, not just scope)
 *   - tool_id                binary identity at the tool registration level
 *   - capability_set_hash    binary-version fingerprint from boruna_capability_list
 *                            (falls back to tool.updated_at if the caller cannot
 *                            resolve it — e.g. boruna-mcp unavailable at key time)
 *   - script                 the .ax source
 *   - input                  JSON-serialised script arguments
 *   - policy                 'allow-all' | 'deny-all' or structured Policy JSON
 */
class BorunaResultCache
{
    /** 24h — long enough to amortise repeat agent loops, short enough that
     *  pathological staleness self-heals without operator intervention. */
    private const TTL_SECONDS = 86400;

    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * @param  string|array<string,mixed>  $policy
     */
    public function get(Tool $tool, Skill $skill, array $input, string|array $policy, ?string $capabilitySetHash = null): ?array
    {
        $cached = $this->cache->get($this->key($tool, $skill, $input, $policy, $capabilitySetHash));

        return is_array($cached) ? $cached : null;
    }

    /**
     * @param  string|array<string,mixed>  $policy
     */
    public function put(Tool $tool, Skill $skill, array $input, string|array $policy, array $output, ?string $capabilitySetHash = null): void
    {
        $this->cache->put(
            $this->key($tool, $skill, $input, $policy, $capabilitySetHash),
            $output,
            self::TTL_SECONDS,
        );
    }

    /**
     * @param  string|array<string,mixed>  $policy
     */
    private function key(Tool $tool, Skill $skill, array $input, string|array $policy, ?string $capabilitySetHash = null): string
    {
        $script = (string) ($skill->configuration['script'] ?? '');

        // Serialize structured policies so two different shapes can't share a
        // cache slot. JSON-encoded arrays get a leading "{" / "[" which
        // can never collide with the literal strings 'allow-all' / 'deny-all'.
        $policyMaterial = is_array($policy)
            ? (string) json_encode($policy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $policy;

        // capability_set_hash (from boruna_capability_list, v1.0+) is a true
        // binary fingerprint. Fall back to tool.updated_at when unavailable.
        $binaryMarker = $capabilitySetHash ?? ($tool->updated_at?->toISOString() ?? '');

        $material = implode('|', [
            (string) $tool->id,
            $binaryMarker,
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
