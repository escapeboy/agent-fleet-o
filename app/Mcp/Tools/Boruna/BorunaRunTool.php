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

    protected string $description = 'Execute a Boruna deterministic .ax script. Runs in a capability-safe VM with explicit gates (net.fetch, fs.read, llm.call, etc.). Use inline mode for ad-hoc scripts or skill mode for saved reusable scripts.';

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
                ->description('(inline mode) Capability policy: allow-all or deny-all (default: deny-all)')
                ->enum(['allow-all', 'deny-all']),
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

        $tool = $this->resolveBorунaTool($teamId, $validated['boruna_tool_id'] ?? null);

        if (! $tool) {
            return $this->failedPreconditionError('No active Boruna tool found. Create an mcp_stdio Tool pointing to the Boruna binary.');
        }

        $policy = $validated['policy'] ?? 'deny-all';
        $arguments = array_filter([
            'script' => $script,
            'policy' => $policy,
            'input' => isset($validated['input']) ? json_encode($validated['input']) : null,
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

    private function resolveBorунaTool(string $teamId, ?string $toolId): ?Tool
    {
        if ($toolId) {
            return Tool::where('id', $toolId)
                ->where('team_id', $teamId)
                ->where('type', 'mcp_stdio')
                ->where('status', 'active')
                ->first();
        }

        return Tool::where('team_id', $teamId)
            ->where('type', 'mcp_stdio')
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereRaw("transport_config->>'command' ILIKE '%boruna%'")
                    ->orWhereRaw("transport_config->>'command' ILIKE '%boruna-mcp%'");
            })
            ->first();
    }
}
