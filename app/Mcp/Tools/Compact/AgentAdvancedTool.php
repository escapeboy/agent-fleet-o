<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Agent\AgentConfigHistoryTool;
use App\Mcp\Tools\Agent\AgentFeedbackListTool;
use App\Mcp\Tools\Agent\AgentFeedbackStatsTool;
use App\Mcp\Tools\Agent\AgentFeedbackSubmitTool;
use App\Mcp\Tools\Agent\AgentRollbackConfigTool;
use App\Mcp\Tools\Agent\AgentRuntimeStateTool;
use App\Mcp\Tools\Agent\AgentSkillSyncTool;
use App\Mcp\Tools\Agent\AgentToolSyncTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class AgentAdvancedTool extends CompactTool
{
    protected string $name = 'agent_advanced';

    protected string $description = <<<'TXT'
Auxiliary agent operations beyond core CRUD: configuration history, rollback, live runtime inspection, skill/tool wiring, user feedback. For create/update/delete/toggle use `agent_manage`. Every action requires `agent_id`; remaining params depend on `action`.

Actions:
- config_history (read) — agent_id. Past config snapshots with timestamps.
- rollback (DESTRUCTIVE) — agent_id, version. Overwrites the current config with the named snapshot — current state is lost unless already snapshotted.
- runtime_state (read) — agent_id. Last execution status, queue depth, error counters.
- skill_sync (write) — agent_id, skill_ids[]. Replaces attached skills (full set semantics).
- tool_sync (write) — agent_id, tool_ids[]. Replaces attached tools (full set semantics).
- feedback_submit (write) — agent_id, rating (1-5), comment.
- feedback_list (read) — agent_id. Recent feedback entries.
- feedback_stats (read) — agent_id. Aggregate score + sentiment.
TXT;

    protected function toolMap(): array
    {
        return [
            'config_history' => AgentConfigHistoryTool::class,
            'rollback' => AgentRollbackConfigTool::class,
            'runtime_state' => AgentRuntimeStateTool::class,
            'skill_sync' => AgentSkillSyncTool::class,
            'tool_sync' => AgentToolSyncTool::class,
            'feedback_submit' => AgentFeedbackSubmitTool::class,
            'feedback_list' => AgentFeedbackListTool::class,
            'feedback_stats' => AgentFeedbackStatsTool::class,
        ];
    }
}
