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

    protected string $description = <<<'TXT'
Event-driven trigger rules — when-this-then-that automations that fire on signals or domain events. Rules evaluate conditions (expression-based) against the event payload and execute actions (start_experiment, send_outbound, etc.). `test` is a dry-run that returns whether the rule would have matched without executing actions.

Actions:
- list (read) — optional: event filter, status filter.
- get (read) — trigger_id.
- create (write) — name, event (e.g. "signal.ingested"), conditions (array of expressions), actions (array of action specs).
- update (write) — trigger_id + any creatable field.
- delete (DESTRUCTIVE) — trigger_id. Future events stop matching this rule.
- test (read — costs no credits) — trigger_id, sample payload. Returns matched (bool), action_preview (what would have run).
TXT;

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
