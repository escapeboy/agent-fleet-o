<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Boruna\BorunaCapabilityListTool;
use App\Mcp\Tools\Boruna\BorunaEvidenceTool;
use App\Mcp\Tools\Boruna\BorunaRunTool;
use App\Mcp\Tools\Boruna\BorunaSkillManageTool;
use App\Mcp\Tools\Boruna\BorunaValidateTool;

class BorunaManageTool extends CompactTool
{
    protected string $name = 'boruna_manage';

    protected string $description = 'Manage Boruna evaluation framework. Actions: run (agent_id, benchmark), validate (run_id — validate results), evidence (run_id — get evidence details), capability_list (list evaluation capabilities), skill_manage (manage Boruna skills).';

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
