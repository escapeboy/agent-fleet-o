<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Crew\CrewCreateTool;
use App\Mcp\Tools\Crew\CrewExecuteTool;
use App\Mcp\Tools\Crew\CrewExecutionsListTool;
use App\Mcp\Tools\Crew\CrewExecutionStatusTool;
use App\Mcp\Tools\Crew\CrewGetTool;
use App\Mcp\Tools\Crew\CrewListTool;
use App\Mcp\Tools\Crew\CrewUpdateTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class CrewManageTool extends CompactTool
{
    protected string $name = 'crew_manage';

    protected string $description = <<<'TXT'
Multi-agent crews — coordinated teams of agents that decompose a goal across roles (sequential, parallel, or hierarchical process). `execute` is async: it queues `ExecuteCrewJob` and returns immediately with an `execution_id`; poll `execution_status` for progress. Each crew member must be a real agent_id in the same team.

Actions:
- list / get (read) — list all or fetch one (crew_id).
- create (write) — name, process_type (sequential|parallel|hierarchical), agents[] (array of {agent_id, role}).
- update (write) — crew_id + any creatable field.
- delete (DESTRUCTIVE) — crew_id. Soft-deletes the crew.
- execute (write — long-running) — crew_id, goal. Reserves budget, returns execution_id.
- execution_status (read) — crew_id, execution_id. Status, current task, partial results.
- executions_list (read) — crew_id; optional limit.
TXT;

    protected function toolMap(): array
    {
        return [
            'list' => CrewListTool::class,
            'get' => CrewGetTool::class,
            'create' => CrewCreateTool::class,
            'update' => CrewUpdateTool::class,
            'execute' => CrewExecuteTool::class,
            'execution_status' => CrewExecutionStatusTool::class,
            'executions_list' => CrewExecutionsListTool::class,
        ];
    }
}
