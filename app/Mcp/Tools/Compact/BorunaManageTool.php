<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Boruna\BorunaCapabilityListTool;
use App\Mcp\Tools\Boruna\BorunaEvidenceTool;
use App\Mcp\Tools\Boruna\BorunaPolicyValidateTool;
use App\Mcp\Tools\Boruna\BorunaRunTool;
use App\Mcp\Tools\Boruna\BorunaSkillManageTool;
use App\Mcp\Tools\Boruna\BorunaValidateTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class BorunaManageTool extends CompactTool
{
    protected string $name = 'boruna_manage';

    protected string $description = 'Boruna deterministic-script runtime (v1.x LTS) — execute and manage capability-safe .ax scripts. Actions: run (execute inline .ax or saved skill, supports limits), validate (syntax/semantic check), policy_validate (strict policy JSON validation, v0.4.0+), evidence (capability/effect evidence for a run), capability_list (list capabilities + capability_set_hash), skill_manage (CRUD on boruna_script skills). Requires an active mcp_stdio Tool pointing to the Boruna binary.';

    protected function toolMap(): array
    {
        return [
            'run' => BorunaRunTool::class,
            'validate' => BorunaValidateTool::class,
            'policy_validate' => BorunaPolicyValidateTool::class,
            'evidence' => BorunaEvidenceTool::class,
            'capability_list' => BorunaCapabilityListTool::class,
            'skill_manage' => BorunaSkillManageTool::class,
        ];
    }
}
