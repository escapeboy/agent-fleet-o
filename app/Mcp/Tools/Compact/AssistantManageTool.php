<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Assistant\AssistantConversationClearTool;
use App\Mcp\Tools\Assistant\AssistantConversationGetTool;
use App\Mcp\Tools\Assistant\AssistantConversationListTool;
use App\Mcp\Tools\Assistant\AssistantSendMessageTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class AssistantManageTool extends CompactTool
{
    protected string $name = 'assistant_manage';

    protected string $description = <<<'TXT'
FleetQ AI assistant conversations — the in-app chat panel that can call MCP tools on the user's behalf with role-gated authorization (read for all, write for Member+, destructive for Admin/Owner). Conversations bind to a context object (experiment, project, agent, crew, workflow) on first message.

Actions:
- conversation_list (read) — optional: limit, context_type filter.
- conversation_get (read) — conversation_id. Full history including `tool_calls` / `tool_results`.
- send_message (write) — message; optional: conversation_id (omit to start new), context_type, context_id, attachments[]. Triggers a synchronous tool-loop LLM call; consumes team credits.
- conversation_clear (DESTRUCTIVE) — conversation_id. Erases all messages, retains the conversation shell.
TXT;

    protected function toolMap(): array
    {
        return [
            'conversation_list' => AssistantConversationListTool::class,
            'conversation_get' => AssistantConversationGetTool::class,
            'send_message' => AssistantSendMessageTool::class,
            'conversation_clear' => AssistantConversationClearTool::class,
        ];
    }
}
