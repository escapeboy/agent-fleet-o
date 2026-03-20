<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Assistant\AssistantConversationClearTool;
use App\Mcp\Tools\Assistant\AssistantConversationGetTool;
use App\Mcp\Tools\Assistant\AssistantConversationListTool;
use App\Mcp\Tools\Assistant\AssistantSendMessageTool;

class AssistantManageTool extends CompactTool
{
    protected string $name = 'assistant_manage';

    protected string $description = 'Manage AI assistant conversations. Actions: conversation_list, conversation_get (conversation_id), send_message (conversation_id, message), conversation_clear (conversation_id).';

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
