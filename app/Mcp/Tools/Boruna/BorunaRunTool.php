<?php

namespace App\Mcp\Tools\Boruna;

use App\Domain\Skill\Actions\ExecuteBorunaScriptSkillAction;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\McpStdioClient;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool as McpTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * Run a Boruna deterministic script directly or via a saved skill.
 *
 * Two modes:
 *  - inline: provide `script` + `boruna_tool_id` directly
 *  - skill:  provide `skill_id` to execute a saved boruna_script skill
 */
#[IsDestructive]
class BorunaRunTool extends McpTool
{
    use HasStructuredErrors;

    protected string $name = 'boruna_run';

    protected string $description = 'Execute a Boruna deterministic .ax script. Runs in a capability-safe VM with explicit gates (net.fetch, fs.read, llm.call, etc.). Use inline mode for ad-hoc scripts or skill mode for saved reusable scripts. Capability gating supports both shorthand (allow-all/deny-all) and Boruna v0.2.0 structured Policy objects (default_allow + per-capability rules + net_policy).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'mode' => $schema->string()
                ->description('Execution mode: inline (run script directly) or skill (run a saved boruna_script skill)')
                ->enum(['inline', 'skill'])
                ->required(),
            'script' => $schema->string()
                ->description('(inline mode) The .ax script source code to execute'),
            'policy' => $schema->string()
                ->description('(inline mode) Legacy capability policy shorthand: "allow-all" or "deny-all" (default: deny-all). For Boruna v0.2.0+ fine-grained gating, use policy_structured instead.')
                ->enum(['allow-all', 'deny-all']),
            'policy_structured' => $schema->object()
                ->description('(inline mode, Boruna v0.2.0+) Structured Capability Policy object with required default_allow (bool), optional rules (per-capability {allow, budget}), and optional net_policy (allowed_domains, allowed_methods, max_response_bytes, timeout_ms, allow_redirects). Capability keys: net.fetch, fs.read, fs.write, db.query, ui.render, time.now, random, llm.call, actor.spawn, actor.send. When set, takes precedence over the legacy policy parameter. See https://github.com/escapeboy/boruna/blob/v0.2.0/docs/reference/policy-schema.md.'),
            'boruna_tool_id' => $schema->string()
                ->description('(inline mode) UUID of the mcp_stdio Tool pointing to the Boruna binary. If omitted, auto-detects.'),
            'input' => $schema->object()
                ->description('Optional input data passed to the script as JSON'),
            'skill_id' => $schema->string()
                ->description('(skill mode) UUID of the boruna_script Skill to execute'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'mode' => 'required|in:inline,skill',
            'script' => 'nullable|string',
            'policy' => 'nullable|in:allow-all,deny-all',
            'policy_structured' => 'nullable|array',
            'policy_structured.default_allow' => 'required_with:policy_structured|boolean',
            'boruna_tool_id' => 'nullable|uuid',
            'input' => 'nullable|array',
            'skill_id' => 'nullable|uuid',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        $userId = auth()->id();

        if ($validated['mode'] === 'skill') {
            return $this->runSkillMode($validated, $teamId, $userId);
        }

        return $this->runInlineMode($validated, $teamId);
    }

    private function runInlineMode(array $validated, string $teamId): Response
    {
        $script = $validated['script'] ?? null;

        if (! $script) {
            return $this->invalidArgumentError('script is required in inline mode.');
        }

        $tool = $this->resolveBorunaTool($teamId, $validated['boruna_tool_id'] ?? null);

        if (! $tool) {
            return $this->failedPreconditionError('No active Boruna tool found. Create an mcp_stdio Tool pointing to the Boruna binary.');
        }

        // policy_structured (v0.2.0+ Capability Policy object) wins over the
        // legacy string shorthand when both are sent.
        $policy = $validated['policy_structured']
            ?? $validated['policy']
            ?? 'deny-all';

        // Boruna v0.2.0 boruna_run accepts `source` (renamed from `script` in
        // earlier integration drafts). It does not accept an `input` param —
        // see ExecuteBorunaScriptSkillAction for the rationale.
        $arguments = array_filter([
            'source' => $script,
            'policy' => $policy,
        ], fn ($v) => $v !== null);

        try {
            $output = app(McpStdioClient::class)->callTool($tool, 'boruna_run', $arguments);

            return Response::text(json_encode([
                'success' => true,
                'mode' => 'inline',
                'policy' => $policy,
                'output' => $output,
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    private function runSkillMode(array $validated, string $teamId, string $userId): Response
    {
        $skillId = $validated['skill_id'] ?? null;

        if (! $skillId) {
            return $this->invalidArgumentError('skill_id is required in skill mode.');
        }

        $skill = Skill::where('id', $skillId)
            ->where('team_id', $teamId)
            ->where('type', SkillType::BorunaScript->value)
            ->first();

        if (! $skill) {
            return $this->notFoundError('Boruna skill');
        }

        try {
            $result = app(ExecuteBorunaScriptSkillAction::class)->execute(
                skill: $skill,
                input: $validated['input'] ?? [],
                teamId: $teamId,
                userId: $userId,
            );

            return Response::text(json_encode([
                'success' => $result['output'] !== null,
                'mode' => 'skill',
                'skill_id' => $skill->id,
                'skill_name' => $skill->name,
                'execution_id' => $result['execution']->id,
                'output' => $result['output'],
                'duration_ms' => $result['execution']->duration_ms,
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    private function resolveBorunaTool(string $teamId, ?string $toolId): ?Tool
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
}
