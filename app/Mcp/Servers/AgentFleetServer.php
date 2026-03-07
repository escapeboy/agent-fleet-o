<?php

namespace App\Mcp\Servers;

use App\Mcp\Concerns\BootstrapsMcpAuth;
use App\Mcp\Tools\Agent\AgentCreateTool;
use App\Mcp\Tools\Agent\AgentDeleteTool;
use App\Mcp\Tools\Agent\AgentGetTool;
use App\Mcp\Tools\Agent\AgentListTool;
use App\Mcp\Tools\Agent\AgentSkillSyncTool;
use App\Mcp\Tools\Agent\AgentTemplatesListTool;
use App\Mcp\Tools\Agent\AgentToggleStatusTool;
use App\Mcp\Tools\Agent\AgentToolSyncTool;
use App\Mcp\Tools\Agent\AgentUpdateTool;
use App\Mcp\Tools\Approval\ApprovalApproveTool;
use App\Mcp\Tools\Approval\ApprovalCompleteHumanTaskTool;
use App\Mcp\Tools\Approval\ApprovalListTool;
use App\Mcp\Tools\Approval\ApprovalRejectTool;
use App\Mcp\Tools\Approval\ApprovalWebhookTool;
use App\Mcp\Tools\Artifact\ArtifactContentTool;
use App\Mcp\Tools\Artifact\ArtifactDownloadTool;
use App\Mcp\Tools\Artifact\ArtifactGetTool;
use App\Mcp\Tools\Artifact\ArtifactListTool;
use App\Mcp\Tools\Budget\BudgetCheckTool;
use App\Mcp\Tools\Budget\BudgetForecastTool;
use App\Mcp\Tools\Budget\BudgetSummaryTool;
use App\Mcp\Tools\Cache\SemanticCachePurgeTool;
use App\Mcp\Tools\Cache\SemanticCacheStatsTool;
use App\Mcp\Tools\Compute\ComputeManageTool;
use App\Mcp\Tools\Credential\CredentialCreateTool;
use App\Mcp\Tools\Credential\CredentialGetTool;
use App\Mcp\Tools\Credential\CredentialListTool;
use App\Mcp\Tools\Credential\CredentialRotateTool;
use App\Mcp\Tools\Credential\CredentialUpdateTool;
use App\Mcp\Tools\Crew\CrewCreateTool;
use App\Mcp\Tools\Crew\CrewExecuteTool;
use App\Mcp\Tools\Crew\CrewExecutionsListTool;
use App\Mcp\Tools\Crew\CrewExecutionStatusTool;
use App\Mcp\Tools\Crew\CrewGetTool;
use App\Mcp\Tools\Crew\CrewListTool;
use App\Mcp\Tools\Crew\CrewUpdateTool;
use App\Mcp\Tools\Email\EmailTemplateCreateTool;
use App\Mcp\Tools\Email\EmailTemplateDeleteTool;
use App\Mcp\Tools\Email\EmailTemplateGetTool;
use App\Mcp\Tools\Email\EmailTemplateListTool;
use App\Mcp\Tools\Email\EmailTemplateUpdateTool;
use App\Mcp\Tools\Email\EmailThemeCreateTool;
use App\Mcp\Tools\Email\EmailThemeDeleteTool;
use App\Mcp\Tools\Email\EmailThemeGetTool;
use App\Mcp\Tools\Email\EmailThemeListTool;
use App\Mcp\Tools\Email\EmailTemplateGenerateTool;
use App\Mcp\Tools\Email\EmailThemeUpdateTool;
use App\Mcp\Tools\Evolution\EvolutionAnalyzeTool;
use App\Mcp\Tools\Evolution\EvolutionApplyTool;
use App\Mcp\Tools\Evolution\EvolutionProposalListTool;
use App\Mcp\Tools\Evolution\EvolutionRejectTool;
use App\Mcp\Tools\Experiment\ExperimentCostTool;
use App\Mcp\Tools\Experiment\ExperimentCreateTool;
use App\Mcp\Tools\Experiment\ExperimentGetTool;
use App\Mcp\Tools\Experiment\ExperimentKillTool;
use App\Mcp\Tools\Experiment\ExperimentListTool;
use App\Mcp\Tools\Experiment\ExperimentPauseTool;
use App\Mcp\Tools\Experiment\ExperimentResumeTool;
use App\Mcp\Tools\Experiment\ExperimentRetryFromStepTool;
use App\Mcp\Tools\Experiment\ExperimentRetryTool;
use App\Mcp\Tools\Experiment\ExperimentShareTool;
use App\Mcp\Tools\Experiment\ExperimentStartTool;
use App\Mcp\Tools\Experiment\ExperimentStepsTool;
use App\Mcp\Tools\Experiment\ExperimentValidTransitionsTool;
use App\Mcp\Tools\Integration\IntegrationManageTool;
use App\Mcp\Tools\Marketplace\MarketplaceAnalyticsTool;
use App\Mcp\Tools\Marketplace\MarketplaceBrowseTool;
use App\Mcp\Tools\Marketplace\MarketplaceCategoriesListTool;
use App\Mcp\Tools\Marketplace\MarketplaceInstallTool;
use App\Mcp\Tools\Marketplace\MarketplacePublishTool;
use App\Mcp\Tools\Marketplace\MarketplaceReviewTool;
use App\Mcp\Tools\Memory\MemoryDeleteTool;
use App\Mcp\Tools\Memory\MemoryListRecentTool;
use App\Mcp\Tools\Memory\MemorySearchTool;
use App\Mcp\Tools\Memory\MemoryStatsTool;
use App\Mcp\Tools\Memory\MemoryUploadKnowledgeTool;
use App\Mcp\Tools\Outbound\ConnectorConfigDeleteTool;
use App\Mcp\Tools\Outbound\ConnectorConfigGetTool;
use App\Mcp\Tools\Outbound\ConnectorConfigListTool;
use App\Mcp\Tools\Outbound\ConnectorConfigSaveTool;
use App\Mcp\Tools\Outbound\ConnectorConfigTestTool;
use App\Mcp\Tools\Project\ProjectActivateTool;
use App\Mcp\Tools\Project\ProjectArchiveTool;
use App\Mcp\Tools\Project\ProjectCreateTool;
use App\Mcp\Tools\Project\ProjectGetTool;
use App\Mcp\Tools\Project\ProjectListTool;
use App\Mcp\Tools\Project\ProjectPauseTool;
use App\Mcp\Tools\Project\ProjectResumeTool;
use App\Mcp\Tools\Project\ProjectTriggerRunTool;
use App\Mcp\Tools\Project\ProjectUpdateTool;
use App\Mcp\Tools\RunPod\RunPodManageTool;
use App\Mcp\Tools\Shared\ApiTokenManageTool;
use App\Mcp\Tools\Shared\CustomEndpointManageTool;
use App\Mcp\Tools\Shared\LocalLlmTool;
use App\Mcp\Tools\Shared\NotificationTool;
use App\Mcp\Tools\Shared\TeamByokCredentialManageTool;
use App\Mcp\Tools\Shared\TeamGetTool;
use App\Mcp\Tools\Shared\TeamMembersTool;
use App\Mcp\Tools\Shared\TeamUpdateTool;
use App\Mcp\Tools\Signal\AlertConnectorTool;
use App\Mcp\Tools\Signal\ConnectorBindingDeleteTool;
use App\Mcp\Tools\Signal\ConnectorBindingTool;
use App\Mcp\Tools\Signal\ContactManageTool;
use App\Mcp\Tools\Signal\HttpMonitorTool;
use App\Mcp\Tools\Signal\InboundConnectorManageTool;
use App\Mcp\Tools\Signal\SignalGetTool;
use App\Mcp\Tools\Signal\SignalIngestTool;
use App\Mcp\Tools\Signal\SignalListTool;
use App\Mcp\Tools\Signal\SlackConnectorTool;
use App\Mcp\Tools\Signal\TicketConnectorTool;
use App\Mcp\Tools\Skill\BrowserSkillTool;
use App\Mcp\Tools\Skill\CodeExecutionTool;
use App\Mcp\Tools\Skill\GuardrailTool;
use App\Mcp\Tools\Skill\MultiModelConsensusTool;
use App\Mcp\Tools\Skill\SkillCreateTool;
use App\Mcp\Tools\Skill\SkillGetTool;
use App\Mcp\Tools\Skill\SkillListTool;
use App\Mcp\Tools\Skill\SkillUpdateTool;
use App\Mcp\Tools\Skill\SkillVersionsTool;
use App\Mcp\Tools\System\AuditLogTool;
use App\Mcp\Tools\System\DashboardKpisTool;
use App\Mcp\Tools\System\GlobalSettingsUpdateTool;
use App\Mcp\Tools\System\SystemHealthTool;
use App\Mcp\Tools\System\SystemVersionCheckTool;
use App\Mcp\Tools\Telegram\TelegramBotTool;
use App\Mcp\Tools\Tool\ToolActivateTool;
use App\Mcp\Tools\Tool\ToolBashPolicyTool;
use App\Mcp\Tools\Tool\ToolCreateTool;
use App\Mcp\Tools\Tool\ToolDeactivateTool;
use App\Mcp\Tools\Tool\ToolDeleteTool;
use App\Mcp\Tools\Tool\ToolDiscoverMcpTool;
use App\Mcp\Tools\Tool\ToolGetTool;
use App\Mcp\Tools\Tool\ToolImportMcpTool;
use App\Mcp\Tools\Tool\ToolListTool;
use App\Mcp\Tools\Tool\ToolSshFingerprintsTool;
use App\Mcp\Tools\Tool\ToolUpdateTool;
use App\Mcp\Tools\Trigger\TriggerRuleCreateTool;
use App\Mcp\Tools\Trigger\TriggerRuleDeleteTool;
use App\Mcp\Tools\Trigger\TriggerRuleListTool;
use App\Mcp\Tools\Trigger\TriggerRuleTestTool;
use App\Mcp\Tools\Trigger\TriggerRuleUpdateTool;
use App\Mcp\Tools\Webhook\WebhookCreateTool;
use App\Mcp\Tools\Webhook\WebhookDeleteTool;
use App\Mcp\Tools\Webhook\WebhookListTool;
use App\Mcp\Tools\Webhook\WebhookUpdateTool;
use App\Mcp\Tools\Workflow\WorkflowActivateTool;
use App\Mcp\Tools\Workflow\WorkflowCreateTool;
use App\Mcp\Tools\Workflow\WorkflowDuplicateTool;
use App\Mcp\Tools\Workflow\WorkflowEstimateCostTool;
use App\Mcp\Tools\Workflow\WorkflowExecutionChainTool;
use App\Mcp\Tools\Workflow\WorkflowGenerateTool;
use App\Mcp\Tools\Workflow\WorkflowGetTool;
use App\Mcp\Tools\Workflow\WorkflowListTool;
use App\Mcp\Tools\Workflow\WorkflowSaveGraphTool;
use App\Mcp\Tools\Workflow\WorkflowSuggestionTool;
use App\Mcp\Tools\Workflow\WorkflowTimeGateTool;
use App\Mcp\Tools\Workflow\WorkflowUpdateTool;
use App\Mcp\Tools\Workflow\WorkflowValidateTool;
use Laravel\Mcp\Server;

class AgentFleetServer extends Server
{
    use BootstrapsMcpAuth;

    protected string $name = 'FleetQ';

    protected string $version = '1.1.0';

    // Return all tools in a single page — MCP clients like Codex don't follow cursors
    public int $defaultPaginationLength = 200;

    public int $maxPaginationLength = 200;

    protected string $instructions = 'FleetQ MCP Server — AI Agent Mission Control Platform. Manage agents, experiments, projects, workflows, crews, skills, tools, credentials, approvals, signals, budgets, marketplace, artifacts, webhooks, email themes, email templates, and team settings.';

    protected function boot(): void
    {
        $this->bootstrapMcpAuth();
    }

    protected array $tools = [
        // Agent (9)
        AgentListTool::class,
        AgentGetTool::class,
        AgentCreateTool::class,
        AgentUpdateTool::class,
        AgentToggleStatusTool::class,
        AgentTemplatesListTool::class,
        AgentSkillSyncTool::class,
        AgentToolSyncTool::class,
        AgentDeleteTool::class,

        // Evolution (4)
        EvolutionProposalListTool::class,
        EvolutionAnalyzeTool::class,
        EvolutionApplyTool::class,
        EvolutionRejectTool::class,

        // Crew (7)
        CrewListTool::class,
        CrewGetTool::class,
        CrewCreateTool::class,
        CrewUpdateTool::class,
        CrewExecuteTool::class,
        CrewExecutionStatusTool::class,
        CrewExecutionsListTool::class,

        // Experiment (13)
        ExperimentListTool::class,
        ExperimentGetTool::class,
        ExperimentCreateTool::class,
        ExperimentStartTool::class,
        ExperimentPauseTool::class,
        ExperimentResumeTool::class,
        ExperimentRetryTool::class,
        ExperimentRetryFromStepTool::class,
        ExperimentKillTool::class,
        ExperimentValidTransitionsTool::class,
        ExperimentCostTool::class,
        ExperimentStepsTool::class,
        ExperimentShareTool::class,

        // Skill (9)
        SkillListTool::class,
        SkillGetTool::class,
        SkillCreateTool::class,
        SkillUpdateTool::class,
        SkillVersionsTool::class,
        GuardrailTool::class,
        MultiModelConsensusTool::class,
        CodeExecutionTool::class,
        BrowserSkillTool::class,

        // Tool (7)
        ToolListTool::class,
        ToolGetTool::class,
        ToolCreateTool::class,
        ToolUpdateTool::class,
        ToolDeleteTool::class,
        ToolActivateTool::class,
        ToolDeactivateTool::class,
        ToolDiscoverMcpTool::class,
        ToolImportMcpTool::class,
        ToolSshFingerprintsTool::class,
        ToolBashPolicyTool::class,

        // Credential (5)
        CredentialListTool::class,
        CredentialGetTool::class,
        CredentialCreateTool::class,
        CredentialUpdateTool::class,
        CredentialRotateTool::class,

        // Workflow (11)
        WorkflowListTool::class,
        WorkflowGetTool::class,
        WorkflowCreateTool::class,
        WorkflowUpdateTool::class,
        WorkflowValidateTool::class,
        WorkflowActivateTool::class,
        WorkflowDuplicateTool::class,
        WorkflowSaveGraphTool::class,
        WorkflowEstimateCostTool::class,
        WorkflowGenerateTool::class,
        WorkflowSuggestionTool::class,
        WorkflowTimeGateTool::class,
        WorkflowExecutionChainTool::class,

        // Project (9)
        ProjectListTool::class,
        ProjectGetTool::class,
        ProjectCreateTool::class,
        ProjectUpdateTool::class,
        ProjectActivateTool::class,
        ProjectPauseTool::class,
        ProjectResumeTool::class,
        ProjectTriggerRunTool::class,
        ProjectArchiveTool::class,

        // Approval (5)
        ApprovalListTool::class,
        ApprovalApproveTool::class,
        ApprovalRejectTool::class,
        ApprovalCompleteHumanTaskTool::class,
        ApprovalWebhookTool::class,

        // Signal (11)
        SignalListTool::class,
        SignalGetTool::class,
        SignalIngestTool::class,
        TicketConnectorTool::class,
        AlertConnectorTool::class,
        SlackConnectorTool::class,
        HttpMonitorTool::class,
        InboundConnectorManageTool::class,
        ConnectorBindingTool::class,
        ConnectorBindingDeleteTool::class,
        ContactManageTool::class,

        // Budget (3)
        BudgetSummaryTool::class,
        BudgetCheckTool::class,
        BudgetForecastTool::class,

        // Cache (2)
        SemanticCacheStatsTool::class,
        SemanticCachePurgeTool::class,

        // Marketplace (6)
        MarketplaceBrowseTool::class,
        MarketplacePublishTool::class,
        MarketplaceInstallTool::class,
        MarketplaceAnalyticsTool::class,
        MarketplaceReviewTool::class,
        MarketplaceCategoriesListTool::class,

        // Memory (5)
        MemorySearchTool::class,
        MemoryListRecentTool::class,
        MemoryStatsTool::class,
        MemoryDeleteTool::class,
        MemoryUploadKnowledgeTool::class,

        // Artifact (4)
        ArtifactListTool::class,
        ArtifactGetTool::class,
        ArtifactContentTool::class,
        ArtifactDownloadTool::class,

        // Outbound (5)
        ConnectorConfigListTool::class,
        ConnectorConfigGetTool::class,
        ConnectorConfigSaveTool::class,
        ConnectorConfigDeleteTool::class,
        ConnectorConfigTestTool::class,

        // Webhook (4)
        WebhookListTool::class,
        WebhookCreateTool::class,
        WebhookUpdateTool::class,
        WebhookDeleteTool::class,

        // Shared (7)
        NotificationTool::class,
        TeamGetTool::class,
        TeamUpdateTool::class,
        TeamMembersTool::class,
        LocalLlmTool::class,
        TeamByokCredentialManageTool::class,
        CustomEndpointManageTool::class,
        ApiTokenManageTool::class,

        // Telegram (1)
        TelegramBotTool::class,

        // Trigger (5)
        TriggerRuleListTool::class,
        TriggerRuleCreateTool::class,
        TriggerRuleUpdateTool::class,
        TriggerRuleDeleteTool::class,
        TriggerRuleTestTool::class,

        // Integration (1)
        IntegrationManageTool::class,

        // Compute (1)
        ComputeManageTool::class,

        // RunPod (1)
        RunPodManageTool::class,

        // Email (11)
        EmailThemeListTool::class,
        EmailThemeGetTool::class,
        EmailThemeCreateTool::class,
        EmailThemeUpdateTool::class,
        EmailThemeDeleteTool::class,
        EmailTemplateListTool::class,
        EmailTemplateGetTool::class,
        EmailTemplateCreateTool::class,
        EmailTemplateUpdateTool::class,
        EmailTemplateDeleteTool::class,
        EmailTemplateGenerateTool::class,

        // System (5)
        DashboardKpisTool::class,
        SystemHealthTool::class,
        SystemVersionCheckTool::class,
        AuditLogTool::class,
        GlobalSettingsUpdateTool::class,
    ];
}
