<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillExecution;
use App\Domain\Skill\Services\BorunaResultCache;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\McpStdioClient;
use Illuminate\Support\Facades\Log;

/**
 * Executes a Boruna deterministic script skill via the Boruna MCP stdio server.
 *
 * Boruna provides a capability-safe execution platform for the `.ax` language.
 * Scripts run in a deterministic VM with explicit capability gates
 * (net.fetch, fs.read, fs.write, db.query, llm.call, etc.).
 *
 * Skill configuration keys:
 *   - script          (string, required) Inline .ax script source code
 *   - policy          (mixed, default 'deny-all') Capability policy. Accepts:
 *                       * legacy shorthand string: 'allow-all' | 'deny-all'
 *                       * Boruna v0.2.0+ structured Policy object (array) with
 *                         required `default_allow` (bool), optional `rules`
 *                         (per-capability {allow, budget}), optional `net_policy`.
 *                         See https://github.com/escapeboy/boruna/blob/v0.2.0/docs/reference/policy-schema.md
 *   - boruna_tool_id  (uuid, optional) ID of the mcp_stdio Tool pointing to the Boruna binary.
 *                     Falls back to the first active Boruna tool in the team.
 *   - timeout         (int, default 30) Execution timeout in seconds
 *
 * Costs are not billed to platform credits — Boruna runs locally at zero LLM cost.
 */
class ExecuteBorunaScriptSkillAction
{
    public function __construct(
        private readonly McpStdioClient $client,
        private readonly BorunaResultCache $cache,
    ) {}

    /**
     * @return array{execution: SkillExecution, output: array|null}
     */
    public function execute(
        Skill $skill,
        array $input,
        string $teamId,
        string $userId,
        ?string $agentId = null,
        ?string $experimentId = null,
    ): array {
        $config = is_array($skill->configuration) ? $skill->configuration : [];

        $script = $config['script'] ?? null;

        if (! $script) {
            return $this->failExecution(
                $skill, $teamId, $agentId, $experimentId, $input,
                'Boruna skill missing required configuration: script.',
            );
        }

        $borунaTool = $this->resolveTool($teamId, $config['boruna_tool_id'] ?? null);

        if (! $borунaTool) {
            return $this->failExecution(
                $skill, $teamId, $agentId, $experimentId, $input,
                'No active Boruna tool found for this team. Create an mcp_stdio Tool pointing to the Boruna binary.',
            );
        }

        $policy = $this->normalisePolicy($config['policy'] ?? null);

        $startTime = hrtime(true);

        // Cache hit short-circuit — Boruna runs are deterministic, so identical
        // inputs against the same Tool produce identical outputs. We still
        // record a SkillExecution so the call site sees the same shape.
        $cached = $this->cache->get($borунaTool, $skill, $input, $policy);
        if ($cached !== null) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            $execution = SkillExecution::create([
                'skill_id' => $skill->id,
                'agent_id' => $agentId,
                'experiment_id' => $experimentId,
                'team_id' => $teamId,
                'status' => 'completed',
                'input' => $input,
                'output' => $cached,
                'duration_ms' => $durationMs,
                'cost_credits' => 0,
            ]);

            $skill->recordExecution(true, $durationMs);

            Log::debug('Boruna result cache hit', [
                'skill_id' => $skill->id,
                'tool_id' => $borунaTool->id,
                'duration_ms' => $durationMs,
            ]);

            return ['execution' => $execution, 'output' => $cached];
        }

        // Merge skill input into the script call so scripts can reference $input.
        $arguments = [
            'script' => $script,
            'policy' => $policy,
            'input' => empty($input) ? null : json_encode($input),
        ];

        // Remove null values — Boruna MCP does not accept null parameters
        $arguments = array_filter($arguments, fn ($v) => $v !== null);

        try {
            $rawOutput = $this->client->callTool($borунaTool, 'boruna_run', $arguments);

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            // Attempt to parse as JSON; fall back to plain string output
            $output = json_decode($rawOutput, true);
            if (! is_array($output)) {
                $output = ['output' => $rawOutput];
            }

            $execution = SkillExecution::create([
                'skill_id' => $skill->id,
                'agent_id' => $agentId,
                'experiment_id' => $experimentId,
                'team_id' => $teamId,
                'status' => 'completed',
                'input' => $input,
                'output' => $output,
                'duration_ms' => $durationMs,
                'cost_credits' => 0, // Local execution — zero platform cost
            ]);

            $skill->recordExecution(true, $durationMs);

            // Store in cache only on success — failures are not cached, so the
            // next call will re-execute and either succeed (and cache) or fail again.
            $this->cache->put($borунaTool, $skill, $input, $policy, $output);

            return ['execution' => $execution, 'output' => $output];
        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $skill->recordExecution(false, $durationMs);

            Log::warning('ExecuteBorunaScriptSkillAction failed', [
                'skill_id' => $skill->id,
                'error' => $e->getMessage(),
            ]);

            return $this->failExecution(
                $skill, $teamId, $agentId, $experimentId, $input,
                $e->getMessage(), $durationMs,
            );
        }
    }

    /**
     * Normalise skill-config policy into the shape Boruna's MCP server accepts.
     *
     * Accepts:
     *   - null / missing            → 'deny-all' (safe default)
     *   - 'allow-all' | 'deny-all'  → passed through verbatim (legacy shorthand)
     *   - structured array with `default_allow` boolean → passed through verbatim
     *     (Boruna v0.2.0+ Capability Policy object)
     *   - anything else             → 'deny-all' (defensive — never silently widen access)
     *
     * @return string|array<string,mixed>
     */
    private function normalisePolicy(mixed $policy): string|array
    {
        if (is_array($policy) && array_key_exists('default_allow', $policy) && is_bool($policy['default_allow'])) {
            return $policy;
        }

        if (is_string($policy) && in_array($policy, ['allow-all', 'deny-all'], true)) {
            return $policy;
        }

        return 'deny-all';
    }

    private function resolveTool(string $teamId, ?string $toolId): ?Tool
    {
        if ($toolId) {
            return Tool::where('id', $toolId)
                ->where('team_id', $teamId)
                ->where('type', 'mcp_stdio')
                ->where('status', 'active')
                ->where('subkind', 'boruna')
                ->first();
        }

        return Tool::where('team_id', $teamId)
            ->where('type', 'mcp_stdio')
            ->where('status', 'active')
            ->where('subkind', 'boruna')
            ->first();
    }

    /**
     * @return array{execution: SkillExecution, output: null}
     */
    private function failExecution(
        Skill $skill,
        string $teamId,
        ?string $agentId,
        ?string $experimentId,
        array $input,
        string $errorMessage,
        int $durationMs = 0,
    ): array {
        $execution = SkillExecution::create([
            'skill_id' => $skill->id,
            'agent_id' => $agentId,
            'experiment_id' => $experimentId,
            'team_id' => $teamId,
            'status' => 'failed',
            'input' => $input,
            'output' => null,
            'duration_ms' => $durationMs,
            'cost_credits' => 0,
            'error_message' => $errorMessage,
        ]);

        return ['execution' => $execution, 'output' => null];
    }
}
