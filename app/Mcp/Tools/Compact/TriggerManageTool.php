<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Trigger\TriggerRuleCreateTool;
use App\Mcp\Tools\Trigger\TriggerRuleDeleteTool;
use App\Mcp\Tools\Trigger\TriggerRuleListTool;
use App\Mcp\Tools\Trigger\TriggerRuleTestTool;
use App\Mcp\Tools\Trigger\TriggerRuleUpdateTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class TriggerManageTool extends CompactTool
{
    protected string $name = 'trigger_manage';

    protected string $description = 'Manage event-driven trigger rules. Actions: list, create (name, event, conditions, actions), update (trigger_id + fields), delete (trigger_id), test (trigger_id, sample payload — dry-run).';

    protected function toolMap(): array
    {
        return [
            'list' => TriggerRuleListTool::class,
            'create' => TriggerRuleCreateTool::class,
            'update' => TriggerRuleUpdateTool::class,
            'delete' => TriggerRuleDeleteTool::class,
            'test' => TriggerRuleTestTool::class,
        ];
    }
}
