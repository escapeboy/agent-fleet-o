<?php

namespace App\Mcp\Servers;

use App\Mcp\Concerns\BootstrapsMcpAuth;
use App\Mcp\Tools\Agent\AgentCreateTool;
use App\Mcp\Tools\Agent\AgentGetTool;
use App\Mcp\Tools\Agent\AgentListTool;
use App\Mcp\Tools\Agent\AgentToggleStatusTool;
use App\Mcp\Tools\Agent\AgentUpdateTool;
use App\Mcp\Tools\Approval\ApprovalApproveTool;
use App\Mcp\Tools\Approval\ApprovalCompleteHumanTaskTool;
use App\Mcp\Tools\Approval\ApprovalListTool;
use App\Mcp\Tools\Approval\ApprovalRejectTool;
use App\Mcp\Tools\Budget\BudgetCheckTool;
use App\Mcp\Tools\Budget\BudgetSummaryTool;
use App\Mcp\Tools\Credential\CredentialCreateTool;
use App\Mcp\Tools\Credential\CredentialGetTool;
use App\Mcp\Tools\Credential\CredentialListTool;
use App\Mcp\Tools\Credential\CredentialUpdateTool;
use App\Mcp\Tools\Crew\CrewCreateTool;
use App\Mcp\Tools\Crew\CrewExecuteTool;
use App\Mcp\Tools\Crew\CrewExecutionStatusTool;
use App\Mcp\Tools\Crew\CrewGetTool;
use App\Mcp\Tools\Crew\CrewListTool;
use App\Mcp\Tools\Crew\CrewUpdateTool;
use App\Mcp\Tools\Experiment\ExperimentCreateTool;
use App\Mcp\Tools\Experiment\ExperimentGetTool;
use App\Mcp\Tools\Experiment\ExperimentKillTool;
use App\Mcp\Tools\Experiment\ExperimentListTool;
use App\Mcp\Tools\Experiment\ExperimentPauseTool;
use App\Mcp\Tools\Experiment\ExperimentResumeTool;
use App\Mcp\Tools\Experiment\ExperimentRetryTool;
use App\Mcp\Tools\Experiment\ExperimentValidTransitionsTool;
use App\Mcp\Tools\Marketplace\MarketplaceBrowseTool;
use App\Mcp\Tools\Marketplace\MarketplaceInstallTool;
use App\Mcp\Tools\Marketplace\MarketplacePublishTool;
use App\Mcp\Tools\Memory\MemoryListRecentTool;
use App\Mcp\Tools\Memory\MemorySearchTool;
use App\Mcp\Tools\Memory\MemoryStatsTool;
use App\Mcp\Tools\Project\ProjectArchiveTool;
use App\Mcp\Tools\Project\ProjectCreateTool;
use App\Mcp\Tools\Project\ProjectGetTool;
use App\Mcp\Tools\Project\ProjectListTool;
use App\Mcp\Tools\Project\ProjectPauseTool;
use App\Mcp\Tools\Project\ProjectResumeTool;
use App\Mcp\Tools\Project\ProjectTriggerRunTool;
use App\Mcp\Tools\Project\ProjectUpdateTool;
use App\Mcp\Tools\Signal\SignalIngestTool;
use App\Mcp\Tools\Signal\SignalListTool;
use App\Mcp\Tools\Skill\SkillCreateTool;
use App\Mcp\Tools\Skill\SkillGetTool;
use App\Mcp\Tools\Skill\SkillListTool;
use App\Mcp\Tools\Skill\SkillUpdateTool;
use App\Mcp\Tools\System\AuditLogTool;
use App\Mcp\Tools\System\DashboardKpisTool;
use App\Mcp\Tools\System\SystemHealthTool;
use App\Mcp\Tools\Tool\ToolCreateTool;
use App\Mcp\Tools\Tool\ToolDeleteTool;
use App\Mcp\Tools\Tool\ToolGetTool;
use App\Mcp\Tools\Tool\ToolListTool;
use App\Mcp\Tools\Tool\ToolUpdateTool;
use App\Mcp\Tools\Workflow\WorkflowCreateTool;
use App\Mcp\Tools\Workflow\WorkflowGenerateTool;
use App\Mcp\Tools\Workflow\WorkflowGetTool;
use App\Mcp\Tools\Workflow\WorkflowListTool;
use App\Mcp\Tools\Workflow\WorkflowUpdateTool;
use App\Mcp\Tools\Workflow\WorkflowValidateTool;
use Laravel\Mcp\Server;

class AgentFleetServer extends Server
{
    use BootstrapsMcpAuth;

    protected string $name = 'Agent Fleet';

    protected string $version = '1.0.0';

    protected string $instructions = 'Agent Fleet MCP Server — AI Agent Mission Control Platform. Manage agents, experiments, projects, workflows, crews, skills, tools, credentials, approvals, signals, and budgets.';

    protected function boot(): void
    {
        $this->bootstrapMcpAuth();
    }

    protected array $tools = [
        // Agent (5)
        AgentListTool::class,
        AgentGetTool::class,
        AgentCreateTool::class,
        AgentUpdateTool::class,
        AgentToggleStatusTool::class,

        // Crew (6)
        CrewListTool::class,
        CrewGetTool::class,
        CrewCreateTool::class,
        CrewUpdateTool::class,
        CrewExecuteTool::class,
        CrewExecutionStatusTool::class,

        // Experiment (8)
        ExperimentListTool::class,
        ExperimentGetTool::class,
        ExperimentCreateTool::class,
        ExperimentPauseTool::class,
        ExperimentResumeTool::class,
        ExperimentRetryTool::class,
        ExperimentKillTool::class,
        ExperimentValidTransitionsTool::class,

        // Skill (4)
        SkillListTool::class,
        SkillGetTool::class,
        SkillCreateTool::class,
        SkillUpdateTool::class,

        // Tool (5)
        ToolListTool::class,
        ToolGetTool::class,
        ToolCreateTool::class,
        ToolUpdateTool::class,
        ToolDeleteTool::class,

        // Credential (4)
        CredentialListTool::class,
        CredentialGetTool::class,
        CredentialCreateTool::class,
        CredentialUpdateTool::class,

        // Workflow (6)
        WorkflowListTool::class,
        WorkflowGetTool::class,
        WorkflowCreateTool::class,
        WorkflowUpdateTool::class,
        WorkflowValidateTool::class,
        WorkflowGenerateTool::class,

        // Project (8)
        ProjectListTool::class,
        ProjectGetTool::class,
        ProjectCreateTool::class,
        ProjectUpdateTool::class,
        ProjectPauseTool::class,
        ProjectResumeTool::class,
        ProjectTriggerRunTool::class,
        ProjectArchiveTool::class,

        // Approval (4)
        ApprovalListTool::class,
        ApprovalApproveTool::class,
        ApprovalRejectTool::class,
        ApprovalCompleteHumanTaskTool::class,

        // Signal (2)
        SignalListTool::class,
        SignalIngestTool::class,

        // Budget (2)
        BudgetSummaryTool::class,
        BudgetCheckTool::class,

        // Marketplace (3)
        MarketplaceBrowseTool::class,
        MarketplacePublishTool::class,
        MarketplaceInstallTool::class,

        // Memory (3)
        MemorySearchTool::class,
        MemoryListRecentTool::class,
        MemoryStatsTool::class,

        // System (3)
        DashboardKpisTool::class,
        SystemHealthTool::class,
        AuditLogTool::class,
    ];
}
