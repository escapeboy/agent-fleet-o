<?php

namespace App\Mcp\Servers;

use App\Mcp\Concerns\BootstrapsMcpAuth;
use App\Mcp\Resources\ApprovalsResource;
use App\Mcp\Tools\Compact\AdminManageTool;
use App\Mcp\Tools\Compact\AgentAdvancedTool;
use App\Mcp\Tools\Compact\AgentManageTool;
use App\Mcp\Tools\Compact\ApprovalManageTool;
use App\Mcp\Tools\Compact\ArtifactManageTool;
use App\Mcp\Tools\Compact\AssistantManageTool;
use App\Mcp\Tools\Compact\BorunaManageTool;
use App\Mcp\Tools\Compact\BridgeManageTool;
use App\Mcp\Tools\Compact\BudgetManageTool;
use App\Mcp\Tools\Compact\ChatbotManageTool;
use App\Mcp\Tools\Compact\CredentialManageTool;
use App\Mcp\Tools\Compact\CrewManageTool;
use App\Mcp\Tools\Compact\EmailManageTool;
use App\Mcp\Tools\Compact\EvolutionManageTool;
use App\Mcp\Tools\Compact\ExperimentManageTool;
use App\Mcp\Tools\Compact\GitManageTool;
use App\Mcp\Tools\Compact\IntegrationManageTool;
use App\Mcp\Tools\Integration\IntegrationExecuteTool;
use App\Mcp\Tools\Compact\KnowledgeManageTool;
use App\Mcp\Tools\Compact\MarketplaceManageTool;
use App\Mcp\Tools\Compact\MemoryManageTool;
use App\Mcp\Tools\Compact\OutboundManageTool;
use App\Mcp\Tools\Compact\ProfileManageTool;
use App\Mcp\Tools\Compact\ProjectManageTool;
use App\Mcp\Tools\Compact\SignalConnectorsTool;
use App\Mcp\Tools\Compact\SignalManageTool;
use App\Mcp\Tools\Compact\SkillManageTool;
use App\Mcp\Tools\Compact\SystemManageTool;
use App\Mcp\Tools\Compact\TeamManageTool;
use App\Mcp\Tools\Compact\ToolManageTool;
use App\Mcp\Tools\Compact\TriggerManageTool;
use App\Mcp\Tools\Compact\WebhookManageTool;
use App\Mcp\Tools\Compact\WorkflowGraphTool;
use App\Mcp\Tools\Compact\WorkflowManageTool;
use Laravel\Mcp\Server;

/**
 * Compact MCP server for remote clients (Claude.ai, etc.) that have tool count limits.
 *
 * Consolidates 259 individual tools into 33 meta-tools using the "action" parameter.
 * Each meta-tool delegates to the original tool class — zero logic duplication.
 *
 * For local CLI agents (Claude Code, Codex) that have no tool limit,
 * use AgentFleetServer which exposes all 259 tools individually.
 */
class CompactMcpServer extends Server
{
    use BootstrapsMcpAuth;

    protected string $name = 'FleetQ';

    protected string $version = '1.1.0';

    public int $defaultPaginationLength = 50;

    public int $maxPaginationLength = 50;

    protected string $instructions = <<<'TXT'
FleetQ Compact MCP Server — AI Agent Mission Control Platform.

Each tool supports multiple actions via the "action" parameter.
Example: agent_manage(action: "list") to list agents, agent_manage(action: "get", agent_id: "...") to get details.

All parameters from the original tools are supported — pass them alongside "action".
TXT;

    /** @var array<string, array<string, bool>|string> */
    protected array $capabilities = [
        self::CAPABILITY_TOOLS => [
            'listChanged' => true,
        ],
        self::CAPABILITY_RESOURCES => [
            'listChanged' => false,
        ],
        self::CAPABILITY_PROMPTS => [
            'listChanged' => false,
        ],
    ];

    protected function boot(): void
    {
        $this->bootstrapMcpAuth();
    }

    protected array $tools = [
        // Core operations (most frequently used)
        AgentManageTool::class,
        ProjectManageTool::class,
        WorkflowManageTool::class,
        WorkflowGraphTool::class,
        ExperimentManageTool::class,
        CrewManageTool::class,
        BudgetManageTool::class,
        MemoryManageTool::class,
        SystemManageTool::class,
        CredentialManageTool::class,
        TriggerManageTool::class,

        // Secondary operations
        SkillManageTool::class,
        ToolManageTool::class,
        ApprovalManageTool::class,
        SignalManageTool::class,
        SignalConnectorsTool::class,
        KnowledgeManageTool::class,
        ArtifactManageTool::class,
        OutboundManageTool::class,
        WebhookManageTool::class,
        TeamManageTool::class,
        IntegrationManageTool::class,
        IntegrationExecuteTool::class,
        MarketplaceManageTool::class,

        // Specialized operations
        EmailManageTool::class,
        ChatbotManageTool::class,
        BridgeManageTool::class,
        AssistantManageTool::class,
        GitManageTool::class,
        ProfileManageTool::class,
        AgentAdvancedTool::class,
        EvolutionManageTool::class,
        BorunaManageTool::class,
        AdminManageTool::class,
    ];

    /** @var array<int, class-string<Server\Resource>> */
    protected array $resources = [
        ApprovalsResource::class,
    ];
}
