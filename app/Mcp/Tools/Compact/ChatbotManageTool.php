<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Chatbot\ChatbotAnalyticsSummaryTool;
use App\Mcp\Tools\Chatbot\ChatbotCreateTool;
use App\Mcp\Tools\Chatbot\ChatbotDeleteTool;
use App\Mcp\Tools\Chatbot\ChatbotGetTool;
use App\Mcp\Tools\Chatbot\ChatbotLearningEntriesListTool;
use App\Mcp\Tools\Chatbot\ChatbotListTool;
use App\Mcp\Tools\Chatbot\ChatbotSessionListTool;
use App\Mcp\Tools\Chatbot\ChatbotToggleStatusTool;
use App\Mcp\Tools\Chatbot\ChatbotTokenCreateTool;
use App\Mcp\Tools\Chatbot\ChatbotTokenRevokeTool;
use App\Mcp\Tools\Chatbot\ChatbotUpdateTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ChatbotManageTool extends CompactTool
{
    protected string $name = 'chatbot_manage';

    protected string $description = 'Manage chatbot instances. Actions: list, get (chatbot_id), create (name, agent_id, config), update (chatbot_id + fields), delete (chatbot_id), toggle_status (chatbot_id), token_create (chatbot_id), token_revoke (chatbot_id, token_id), session_list (chatbot_id), analytics (chatbot_id), learning_entries (chatbot_id).';

    protected function toolMap(): array
    {
        return [
            'list' => ChatbotListTool::class,
            'get' => ChatbotGetTool::class,
            'create' => ChatbotCreateTool::class,
            'update' => ChatbotUpdateTool::class,
            'delete' => ChatbotDeleteTool::class,
            'toggle_status' => ChatbotToggleStatusTool::class,
            'token_create' => ChatbotTokenCreateTool::class,
            'token_revoke' => ChatbotTokenRevokeTool::class,
            'session_list' => ChatbotSessionListTool::class,
            'analytics' => ChatbotAnalyticsSummaryTool::class,
            'learning_entries' => ChatbotLearningEntriesListTool::class,
        ];
    }
}
