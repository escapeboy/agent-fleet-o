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

    protected string $description = <<<'TXT'
Embeddable chatbots backed by an existing FleetQ agent. Each instance issues per-widget tokens for embedding, tracks sessions, and exposes analytics + learning entries. Conversations consume team credits via the bound agent's provider.

Actions:
- list / get (read) — list all or fetch one (chatbot_id).
- create (write) — name, agent_id, config (theme, greeting, allowed_origins[]).
- update (write) — chatbot_id + any creatable field.
- delete (DESTRUCTIVE) — chatbot_id. Cascades — also revokes all widget tokens.
- toggle_status (write) — chatbot_id. Flips active ↔ disabled.
- token_create (write) — chatbot_id. Returns an embeddable widget token (display once).
- token_revoke (DESTRUCTIVE) — chatbot_id, token_id. Invalidates a single widget instance.
- session_list / analytics / learning_entries (read) — chatbot_id; optional date range.
TXT;

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
