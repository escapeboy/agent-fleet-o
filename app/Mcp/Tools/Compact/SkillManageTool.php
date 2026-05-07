<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Skill\BrowserSkillTool;
use App\Mcp\Tools\Skill\CodeExecutionTool;
use App\Mcp\Tools\Skill\GuardrailTool;
use App\Mcp\Tools\Skill\MultiModelConsensusTool;
use App\Mcp\Tools\Skill\SkillCreateTool;
use App\Mcp\Tools\Skill\SkillDeleteTool;
use App\Mcp\Tools\Skill\SkillGetTool;
use App\Mcp\Tools\Skill\SkillListTool;
use App\Mcp\Tools\Skill\SkillUpdateTool;
use App\Mcp\Tools\Skill\SkillVersionsTool;
use App\Mcp\Tools\Skill\SupabaseEdgeFunctionSkillTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class SkillManageTool extends CompactTool
{
    protected string $name = 'skill_manage';

    protected string $description = <<<'TXT'
AI skills — reusable units agents invoke (LLM prompt templates, connector calls, rules, hybrid, guardrails). Skill executions are versioned (`SkillVersion`) and metered against the team budget. Direct execution actions (`guardrail`, `multi_model`, `code_exec`, `browser`, `supabase_edge_function`) bypass the agent layer for ad-hoc invocation.

CRUD actions:
- list (read) — optional: type, status filter.
- get (read) — skill_id.
- create (write) — name, type (llm|connector|rule|hybrid|guardrail), config (type-specific object).
- update (write) — skill_id + any creatable field. Bumps version.
- delete (DESTRUCTIVE) — skill_id. Soft-deletes; existing version history retained.
- versions (read) — skill_id. Version log with diffs.

Direct execution (each costs credits):
- guardrail (read — costs LLM credits) — input, rules. Validates input against safety rules.
- multi_model (write — costs LLM credits per model) — prompt, models[]. Runs same prompt across models for consensus.
- code_exec (write — sandboxed) — code, language. Runs in DockerSandboxExecutor.
- browser (write — costs browser credits) — url, actions[]. Headless browser automation.
- supabase_edge_function (write) — function_name, payload. Invokes a deployed Supabase edge function.
TXT;

    protected function toolMap(): array
    {
        return [
            'list' => SkillListTool::class,
            'get' => SkillGetTool::class,
            'create' => SkillCreateTool::class,
            'update' => SkillUpdateTool::class,
            'delete' => SkillDeleteTool::class,
            'versions' => SkillVersionsTool::class,
            'guardrail' => GuardrailTool::class,
            'multi_model' => MultiModelConsensusTool::class,
            'code_exec' => CodeExecutionTool::class,
            'browser' => BrowserSkillTool::class,
            'supabase_edge_function' => SupabaseEdgeFunctionSkillTool::class,
        ];
    }
}
