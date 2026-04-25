<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Boruna\BorunaCapabilityListTool;
use App\Mcp\Tools\Boruna\BorunaEvidenceTool;
use App\Mcp\Tools\Boruna\BorunaRunTool;
use App\Mcp\Tools\Boruna\BorunaSkillManageTool;
use App\Mcp\Tools\Boruna\BorunaValidateTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class BorunaManageTool extends CompactTool
{
    protected string $name = 'boruna_manage';

    protected string $description = 'Boruna deterministic-script runtime — execute and manage capability-safe .ax scripts. Actions: run (execute an inline .ax script or saved boruna_script skill), validate (validate a script before execution), evidence (capability/effect evidence for a completed run), capability_list (list capabilities supported by the configured Boruna binary), skill_manage (CRUD on boruna_script skills). Requires an active mcp_stdio Tool pointing to the Boruna binary.';

    protected function toolMap(): array
    {
        return [
            'run' => BorunaRunTool::class,
            'validate' => BorunaValidateTool::class,
            'evidence' => BorunaEvidenceTool::class,
            'capability_list' => BorunaCapabilityListTool::class,
            'skill_manage' => BorunaSkillManageTool::class,
        ];
    }
}
