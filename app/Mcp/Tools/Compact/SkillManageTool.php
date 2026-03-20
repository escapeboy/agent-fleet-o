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

class SkillManageTool extends CompactTool
{
    protected string $name = 'skill_manage';

    protected string $description = 'Manage AI skills. Actions: list, get (skill_id), create (name, type, config), update (skill_id + fields), delete (skill_id), versions (skill_id), guardrail (input, rules), multi_model (prompt, models), code_exec (code, language), browser (url, actions), supabase_edge_function (function_name, payload).';

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
