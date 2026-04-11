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

    protected string $description = 'Manage multi-agent crews. Actions: list, get (crew_id), create (name, process_type, agents), update (crew_id + fields), execute (crew_id, goal), execution_status (crew_id, execution_id), executions_list (crew_id).';

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
