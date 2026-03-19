<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillExecution;
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
 *   - policy          (string, default 'deny-all') Capability policy: 'allow-all' | 'deny-all'
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

        $policy = $config['policy'] ?? 'deny-all';

        // Boruna's MCP server only supports 'allow-all' or 'deny-all' via the run tool.
        if (! in_array($policy, ['allow-all', 'deny-all'], true)) {
            $policy = 'deny-all';
        }

        // Merge skill input into the script call so scripts can reference $input.
        $arguments = [
            'script' => $script,
            'policy' => $policy,
            'input' => empty($input) ? null : json_encode($input),
        ];

        // Remove null values — Boruna MCP does not accept null parameters
        $arguments = array_filter($arguments, fn ($v) => $v !== null);

        $startTime = hrtime(true);

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

    private function resolveTool(string $teamId, ?string $toolId): ?Tool
    {
        if ($toolId) {
            return Tool::where('id', $toolId)
                ->where('team_id', $teamId)
                ->where('type', 'mcp_stdio')
                ->where('status', 'active')
                ->first();
        }

        // Find the first active Boruna tool for the team by checking the binary name.
        return Tool::where('team_id', $teamId)
            ->where('type', 'mcp_stdio')
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereRaw("transport_config->>'command' ILIKE '%boruna%'")
                    ->orWhereRaw("transport_config->>'command' ILIKE '%boruna-mcp%'");
            })
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
