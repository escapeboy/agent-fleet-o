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
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class TeamManageTool extends CompactTool
{
    protected string $name = 'team_manage';

    protected string $description = <<<'TXT'
Caller's team — settings, membership, BYOK provider credentials, API tokens, notifications, MCP tool visibility. Most write actions require `admin` or `owner` role within the team; `viewer` and `member` are limited to read + their own profile-level toggles (notifications, push_subscription).

Core actions:
- get (read) — team info.
- update (write — admin) — name, settings (object).
- members (write — admin) — sub-actions list/invite/remove on team membership.

LLM provider config:
- local_llm (read) — discovered local LLM agents (Ollama, Codex, Claude Code).
- byok_credential (write — admin) — sub-actions on BYOK keys (Anthropic, OpenAI, Google).
- custom_endpoint (write — admin) — sub-actions on custom OpenAI-compatible LLM endpoints.

Tokens & access:
- api_token (write — admin) — sub-actions create/revoke on Sanctum API tokens; tokens are team-scoped.
- mcp_tool_catalog (read) — all available MCP tools with enable status.
- mcp_tool_preferences (write — admin) — set tool profile or custom enabled list.

Profile-level (any role):
- notification (write) — sub-actions list/dismiss on notifications.
- terms_status / terms_history (read) — current acceptance state and history.
- push_subscription (write) — sub-actions on browser push subscriptions.
- plugin (write — admin) — sub-actions on installed plugins.
TXT;

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
