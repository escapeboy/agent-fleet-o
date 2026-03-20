<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Shared\ApiTokenManageTool;
use App\Mcp\Tools\Shared\CustomEndpointManageTool;
use App\Mcp\Tools\Shared\LocalLlmTool;
use App\Mcp\Tools\Shared\McpToolCatalogTool;
use App\Mcp\Tools\Shared\McpToolPreferencesTool;
use App\Mcp\Tools\Shared\NotificationTool;
use App\Mcp\Tools\Shared\PluginManageTool;
use App\Mcp\Tools\Shared\PushSubscriptionManageTool;
use App\Mcp\Tools\Shared\TeamByokCredentialManageTool;
use App\Mcp\Tools\Shared\TeamGetTool;
use App\Mcp\Tools\Shared\TeamMembersTool;
use App\Mcp\Tools\Shared\TeamUpdateTool;
use App\Mcp\Tools\Shared\TermsAcceptanceHistoryTool;
use App\Mcp\Tools\Shared\TermsAcceptanceStatusTool;

class TeamManageTool extends CompactTool
{
    protected string $name = 'team_manage';

    protected string $description = 'Manage team settings and members. Actions: get (team info), update (name, settings), members (list/invite/remove), local_llm (list local LLM agents), byok_credential (manage BYOK provider keys), custom_endpoint (manage custom LLM endpoints), api_token (create/revoke API tokens), notification (manage notifications), terms_status, terms_history, push_subscription (manage push subscriptions), plugin (manage plugins), mcp_tool_catalog (list available MCP tools with status), mcp_tool_preferences (set tool profile or custom enabled list).';

    protected function toolMap(): array
    {
        return [
            'get' => TeamGetTool::class,
            'update' => TeamUpdateTool::class,
            'members' => TeamMembersTool::class,
            'local_llm' => LocalLlmTool::class,
            'byok_credential' => TeamByokCredentialManageTool::class,
            'custom_endpoint' => CustomEndpointManageTool::class,
            'api_token' => ApiTokenManageTool::class,
            'notification' => NotificationTool::class,
            'terms_status' => TermsAcceptanceStatusTool::class,
            'terms_history' => TermsAcceptanceHistoryTool::class,
            'push_subscription' => PushSubscriptionManageTool::class,
            'plugin' => PluginManageTool::class,
            'mcp_tool_catalog' => McpToolCatalogTool::class,
            'mcp_tool_preferences' => McpToolPreferencesTool::class,
        ];
    }
}
