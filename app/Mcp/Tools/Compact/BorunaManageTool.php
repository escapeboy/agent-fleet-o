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

    protected string $description = <<<'TXT'
Boruna deterministic capability-safe `.ax` script runtime (v1.x LTS) — execute, validate, and audit Boruna scripts. Side effects depend entirely on the script's declared capabilities. Requires an active `mcp_stdio` Tool record pointing to a working Boruna binary; otherwise every action returns `dependency_unavailable`.

Actions:
- run (write — side effects per script capabilities) — inline `.ax` source OR `skill_id` of a saved boruna_script skill; optional: timeout_ms, mem_limit_mb.
- validate (read) — `.ax` source. Syntax + semantic check, no execution.
- policy_validate (read; v0.4.0+) — policy JSON. Strict schema validation.
- evidence (read) — run_id. Capability/effect evidence record for an executed run.
- capability_list (read) — registered capabilities and capability_set_hash.
- skill_manage (write) — sub-actions list/get/create/update/delete on boruna_script-typed skills.
TXT;

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
