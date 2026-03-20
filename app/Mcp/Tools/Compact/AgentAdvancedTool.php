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

class AgentAdvancedTool extends CompactTool
{
    protected string $name = 'agent_advanced';

    protected string $description = 'Advanced agent operations. Actions: config_history (agent_id), rollback (agent_id, version), runtime_state (agent_id), skill_sync (agent_id, skill_ids), tool_sync (agent_id, tool_ids), feedback_submit (agent_id, rating, comment), feedback_list (agent_id), feedback_stats (agent_id).';

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
