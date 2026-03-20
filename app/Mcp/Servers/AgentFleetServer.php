<?php

namespace App\Mcp\Servers;

use App\Mcp\Concerns\BootstrapsMcpAuth;
use App\Mcp\Resources\ApprovalsResource;
use App\Mcp\Tools\Admin\AdminBillingApplyCreditTool;
use App\Mcp\Tools\Admin\AdminBillingRefundTool;
use App\Mcp\Tools\Admin\AdminSecurityOverviewTool;
use App\Mcp\Tools\Admin\AdminTeamBillingDetailTool;
use App\Mcp\Tools\Admin\AdminTeamSuspendTool;
use App\Mcp\Tools\Admin\AdminUserRevokeSessionsTool;
use App\Mcp\Tools\Admin\AdminUserSendPasswordResetTool;
use App\Mcp\Tools\Agent\AgentConfigHistoryTool;
use App\Mcp\Tools\Agent\AgentCreateTool;
use App\Mcp\Tools\Agent\AgentDeleteTool;
use App\Mcp\Tools\Agent\AgentFeedbackListTool;
use App\Mcp\Tools\Agent\AgentFeedbackStatsTool;
use App\Mcp\Tools\Agent\AgentFeedbackSubmitTool;
use App\Mcp\Tools\Agent\AgentGetTool;
use App\Mcp\Tools\Agent\AgentListTool;
use App\Mcp\Tools\Agent\AgentRollbackConfigTool;
use App\Mcp\Tools\Agent\AgentRuntimeStateTool;
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
use App\Mcp\Tools\Assistant\AssistantConversationClearTool;
use App\Mcp\Tools\Assistant\AssistantConversationGetTool;
use App\Mcp\Tools\Assistant\AssistantConversationListTool;
use App\Mcp\Tools\Assistant\AssistantSendMessageTool;
use App\Mcp\Tools\Auth\SocialAccountListTool;
use App\Mcp\Tools\Auth\SocialAccountUnlinkTool;
use App\Mcp\Tools\Boruna\BorunaCapabilityListTool;
use App\Mcp\Tools\Boruna\BorunaEvidenceTool;
use App\Mcp\Tools\Boruna\BorunaRunTool;
use App\Mcp\Tools\Boruna\BorunaSkillManageTool;
use App\Mcp\Tools\Boruna\BorunaValidateTool;
use App\Mcp\Tools\Bridge\BridgeDisconnectTool;
use App\Mcp\Tools\Bridge\BridgeEndpointListTool;
use App\Mcp\Tools\Bridge\BridgeEndpointToggleTool;
use App\Mcp\Tools\Bridge\BridgeListTool;
use App\Mcp\Tools\Bridge\BridgeRenameTool;
use App\Mcp\Tools\Bridge\BridgeSetRoutingTool;
use App\Mcp\Tools\Bridge\BridgeStatusTool;
use App\Mcp\Tools\Budget\BudgetCheckTool;
use App\Mcp\Tools\Budget\BudgetForecastTool;
use App\Mcp\Tools\Budget\BudgetSummaryTool;
use App\Mcp\Tools\Cache\SemanticCachePurgeTool;
use App\Mcp\Tools\Cache\SemanticCacheStatsTool;
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
use App\Mcp\Tools\Compute\ComputeManageTool;
use App\Mcp\Tools\Credential\CredentialCreateTool;
use App\Mcp\Tools\Credential\CredentialDeleteTool;
use App\Mcp\Tools\Credential\CredentialGetTool;
use App\Mcp\Tools\Credential\CredentialListTool;
use App\Mcp\Tools\Credential\CredentialOAuthFinalizeTool;
use App\Mcp\Tools\Credential\CredentialOAuthInitiateTool;
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
use App\Mcp\Tools\Email\EmailTemplateGenerateTool;
use App\Mcp\Tools\Email\EmailTemplateGetTool;
use App\Mcp\Tools\Email\EmailTemplateListTool;
use App\Mcp\Tools\Email\EmailTemplateUpdateTool;
use App\Mcp\Tools\Email\EmailThemeCreateTool;
use App\Mcp\Tools\Email\EmailThemeDeleteTool;
use App\Mcp\Tools\Email\EmailThemeGetTool;
use App\Mcp\Tools\Email\EmailThemeListTool;
use App\Mcp\Tools\Email\EmailThemeUpdateTool;
use App\Mcp\Tools\Evaluation\EvaluationDatasetManageTool;
use App\Mcp\Tools\Evaluation\EvaluationRunTool;
use App\Mcp\Tools\Evolution\EvolutionAnalyzeTool;
use App\Mcp\Tools\Evolution\EvolutionApplyTool;
use App\Mcp\Tools\Evolution\EvolutionApproveTool;
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
use App\Mcp\Tools\Experiment\WorkflowSnapshotListTool;
use App\Mcp\Tools\Feedback\FeedbackListTool;
use App\Mcp\Tools\Feedback\FeedbackUpdateTool;
use App\Mcp\Tools\GitRepository\GitBranchCreateTool;
use App\Mcp\Tools\GitRepository\GitCommitTool;
use App\Mcp\Tools\GitRepository\GitFileListTool;
use App\Mcp\Tools\GitRepository\GitFileReadTool;
use App\Mcp\Tools\GitRepository\GitFileWriteTool;
use App\Mcp\Tools\GitRepository\GitPullRequestCreateTool;
use App\Mcp\Tools\GitRepository\GitPullRequestListTool;
use App\Mcp\Tools\GitRepository\GitRepositoryCreateTool;
use App\Mcp\Tools\GitRepository\GitRepositoryDeleteTool;
use App\Mcp\Tools\GitRepository\GitRepositoryGetTool;
use App\Mcp\Tools\GitRepository\GitRepositoryListTool;
use App\Mcp\Tools\GitRepository\GitRepositoryTestTool;
use App\Mcp\Tools\GitRepository\GitRepositoryUpdateTool;
use App\Mcp\Tools\Integration\IntegrationCapabilitiesTool;
use App\Mcp\Tools\Integration\IntegrationConnectTool;
use App\Mcp\Tools\Integration\IntegrationDisconnectTool;
use App\Mcp\Tools\Integration\IntegrationExecuteTool;
use App\Mcp\Tools\Integration\IntegrationListTool;
use App\Mcp\Tools\Integration\IntegrationPingTool;
use App\Mcp\Tools\Knowledge\KnowledgeBaseCreateTool;
use App\Mcp\Tools\Knowledge\KnowledgeBaseDeleteTool;
use App\Mcp\Tools\Knowledge\KnowledgeBaseIngestTool;
use App\Mcp\Tools\Knowledge\KnowledgeBaseListTool;
use App\Mcp\Tools\Knowledge\KnowledgeBaseSearchTool;
use App\Mcp\Tools\Marketplace\MarketplaceAnalyticsTool;
use App\Mcp\Tools\Marketplace\MarketplaceBrowseTool;
use App\Mcp\Tools\Marketplace\MarketplaceCategoriesListTool;
use App\Mcp\Tools\Marketplace\MarketplaceInstallTool;
use App\Mcp\Tools\Marketplace\MarketplacePublishTool;
use App\Mcp\Tools\Marketplace\MarketplaceReviewTool;
use App\Mcp\Tools\Memory\MemoryAddTool;
use App\Mcp\Tools\Memory\MemoryDeleteTool;
use App\Mcp\Tools\Memory\MemoryListRecentTool;
use App\Mcp\Tools\Memory\MemorySearchTool;
use App\Mcp\Tools\Memory\MemoryStatsTool;
use App\Mcp\Tools\Memory\MemoryUploadKnowledgeTool;
use App\Mcp\Tools\Memory\SupabaseProvisionMemoryTool;
use App\Mcp\Tools\Outbound\ConnectorConfigDeleteTool;
use App\Mcp\Tools\Outbound\ConnectorConfigGetTool;
use App\Mcp\Tools\Outbound\ConnectorConfigListTool;
use App\Mcp\Tools\Outbound\ConnectorConfigSaveTool;
use App\Mcp\Tools\Outbound\ConnectorConfigTestTool;
use App\Mcp\Tools\Profile\ProfileConnectedAccountsTool;
use App\Mcp\Tools\Profile\ProfileGetTool;
use App\Mcp\Tools\Profile\ProfileTwoFactorStatusTool;
use App\Mcp\Tools\Profile\ProfileUpdateTool;
use App\Mcp\Tools\Project\ProjectActivateTool;
use App\Mcp\Tools\Project\ProjectArchiveTool;
use App\Mcp\Tools\Project\ProjectCreateTool;
use App\Mcp\Tools\Project\ProjectGetTool;
use App\Mcp\Tools\Project\ProjectListTool;
use App\Mcp\Tools\Project\ProjectPauseTool;
use App\Mcp\Tools\Project\ProjectRestartTool;
use App\Mcp\Tools\Project\ProjectResumeTool;
use App\Mcp\Tools\Project\ProjectRunGetTool;
use App\Mcp\Tools\Project\ProjectRunListTool;
use App\Mcp\Tools\Project\ProjectScheduleManageTool;
use App\Mcp\Tools\Project\ProjectScheduleNlpTool;
use App\Mcp\Tools\Project\ProjectTriggerRunTool;
use App\Mcp\Tools\Project\ProjectUpdateTool;
use App\Mcp\Tools\RunPod\RunPodManageTool;
use App\Mcp\Tools\Shared\ApiTokenManageTool;
use App\Mcp\Tools\Shared\CustomEndpointManageTool;
use App\Mcp\Tools\Shared\LocalLlmTool;
use App\Mcp\Tools\Shared\NotificationTool;
use App\Mcp\Tools\Shared\PluginManageTool;
use App\Mcp\Tools\Shared\PushSubscriptionManageTool;
use App\Mcp\Tools\Shared\TeamByokCredentialManageTool;
use App\Mcp\Tools\Shared\TeamGetTool;
use App\Mcp\Tools\Shared\TeamMembersTool;
use App\Mcp\Tools\Shared\TeamUpdateTool;
use App\Mcp\Tools\Shared\TermsAcceptanceHistoryTool;
use App\Mcp\Tools\Shared\TermsAcceptanceStatusTool;
use App\Mcp\Tools\Signal\AlertConnectorTool;
use App\Mcp\Tools\Signal\ClearCueConnectorTool;
use App\Mcp\Tools\Signal\ConnectorBindingDeleteTool;
use App\Mcp\Tools\Signal\ConnectorBindingTool;
use App\Mcp\Tools\Signal\ConnectorSubscriptionTool;
use App\Mcp\Tools\Signal\ContactManageTool;
use App\Mcp\Tools\Signal\EmailReplyTool;
use App\Mcp\Tools\Signal\HttpMonitorTool;
use App\Mcp\Tools\Signal\ImapMailboxTool;
use App\Mcp\Tools\Signal\InboundConnectorManageTool;
use App\Mcp\Tools\Signal\IntentScoreTool;
use App\Mcp\Tools\Signal\KgAddFactTool;
use App\Mcp\Tools\Signal\KgEntityFactsTool;
use App\Mcp\Tools\Signal\KgSearchTool;
use App\Mcp\Tools\Signal\SignalGetTool;
use App\Mcp\Tools\Signal\SignalIngestTool;
use App\Mcp\Tools\Signal\SignalListTool;
use App\Mcp\Tools\Signal\SlackConnectorTool;
use App\Mcp\Tools\Signal\SupabaseConnectorTool;
use App\Mcp\Tools\Signal\TicketConnectorTool;
use App\Mcp\Tools\Skill\BrowserSkillTool;
use App\Mcp\Tools\Skill\CodeExecutionTool;
use App\Mcp\Tools\Skill\GuardrailTool;
use App\Mcp\Tools\Skill\MultiModelConsensusTool;
use App\Mcp\Tools\Skill\SkillCreateTool;
use App\Mcp\Tools\Skill\SkillDeleteTool;
use App\Mcp\Tools\Skill\SkillGetTool;
use App\Mcp\Tools\Skill\SkillListTool;
use App\Mcp\Tools\Skill\SkillUpdateTool;
use App\Mcp\Tools\Skill\SkillVersionsTool;
use App\Mcp\Tools\Skill\SupabaseEdgeFunctionSkillTool;
use App\Mcp\Tools\System\AuditLogTool;
use App\Mcp\Tools\System\BlacklistManageTool;
use App\Mcp\Tools\System\DashboardKpisTool;
use App\Mcp\Tools\System\GlobalSettingsUpdateTool;
use App\Mcp\Tools\System\SecurityPolicyManageTool;
use App\Mcp\Tools\System\SystemHealthTool;
use App\Mcp\Tools\System\SystemVersionCheckTool;
use App\Mcp\Tools\Telegram\TelegramBotTool;
use App\Mcp\Tools\Tool\ToolActivateTool;
use App\Mcp\Tools\Tool\ToolBashPolicyTool;
use App\Mcp\Tools\Tool\ToolCreateTool;
use App\Mcp\Tools\Tool\ToolDeactivateTool;
use App\Mcp\Tools\Tool\ToolDeleteTool;
use App\Mcp\Tools\Tool\ToolDiscoverMcpTool;
use App\Mcp\Tools\Tool\ToolEmbeddingManageTool;
use App\Mcp\Tools\Tool\ToolEmbeddingSearchTool;
use App\Mcp\Tools\Tool\ToolGetTool;
use App\Mcp\Tools\Tool\ToolImportMcpTool;
use App\Mcp\Tools\Tool\ToolListTool;
use App\Mcp\Tools\Tool\ToolProbeRemoteMcpTool;
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
use App\Mcp\Tools\Workflow\WorkflowEdgeAddTool;
use App\Mcp\Tools\Workflow\WorkflowEdgeDeleteTool;
use App\Mcp\Tools\Workflow\WorkflowEstimateCostTool;
use App\Mcp\Tools\Workflow\WorkflowExecutionChainTool;
use App\Mcp\Tools\Workflow\WorkflowExportTool;
use App\Mcp\Tools\Workflow\WorkflowGenerateTool;
use App\Mcp\Tools\Workflow\WorkflowGetTool;
use App\Mcp\Tools\Workflow\WorkflowImportTool;
use App\Mcp\Tools\Workflow\WorkflowListTool;
use App\Mcp\Tools\Workflow\WorkflowNodeAddTool;
use App\Mcp\Tools\Workflow\WorkflowNodeDeleteTool;
use App\Mcp\Tools\Workflow\WorkflowNodeUpdateTool;
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

    // Return all tools in a single page — MCP clients like Claude.ai/Codex don't follow cursors
    public int $defaultPaginationLength = 300;

    public int $maxPaginationLength = 300;

    protected string $instructions = 'FleetQ MCP Server — AI Agent Mission Control Platform. Manage agents, experiments, projects, workflows, crews, skills, tools, credentials, approvals, signals, budgets, marketplace, artifacts, webhooks, chatbots, email themes, email templates, and team settings.';

    protected function boot(): void
    {
        $this->bootstrapMcpAuth();

        // Append plugin-contributed MCP tools (registered via FleetPluginServiceProvider::$mcpTools)
        foreach (app('fleet.mcp.tool_classes') as $toolClass) {
            if (! in_array($toolClass, $this->tools, true)) {
                $this->tools[] = $toolClass;
            }
        }
    }

    protected array $tools = [
        // Agent (12)
        AgentListTool::class,
        AgentGetTool::class,
        AgentCreateTool::class,
        AgentUpdateTool::class,
        AgentToggleStatusTool::class,
        AgentTemplatesListTool::class,
        AgentSkillSyncTool::class,
        AgentToolSyncTool::class,
        AgentFeedbackSubmitTool::class,
        AgentFeedbackListTool::class,
        AgentFeedbackStatsTool::class,
        AgentDeleteTool::class,
        AgentConfigHistoryTool::class,
        AgentRollbackConfigTool::class,
        AgentRuntimeStateTool::class,

        // Evolution (5)
        EvolutionProposalListTool::class,
        EvolutionAnalyzeTool::class,
        EvolutionApproveTool::class,
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
        WorkflowSnapshotListTool::class,

        // Skill (10)
        SkillListTool::class,
        SkillGetTool::class,
        SkillCreateTool::class,
        SkillUpdateTool::class,
        SkillDeleteTool::class,
        SkillVersionsTool::class,
        GuardrailTool::class,
        MultiModelConsensusTool::class,
        CodeExecutionTool::class,
        BrowserSkillTool::class,
        SupabaseEdgeFunctionSkillTool::class,

        // Tool (12)
        ToolListTool::class,
        ToolGetTool::class,
        ToolCreateTool::class,
        ToolUpdateTool::class,
        ToolDeleteTool::class,
        ToolActivateTool::class,
        ToolDeactivateTool::class,
        ToolDiscoverMcpTool::class,
        ToolImportMcpTool::class,
        ToolProbeRemoteMcpTool::class,
        ToolSshFingerprintsTool::class,
        ToolBashPolicyTool::class,
        ToolEmbeddingManageTool::class,
        ToolEmbeddingSearchTool::class,

        // Credential (8)
        CredentialListTool::class,
        CredentialGetTool::class,
        CredentialCreateTool::class,
        CredentialUpdateTool::class,
        CredentialDeleteTool::class,
        CredentialRotateTool::class,
        CredentialOAuthInitiateTool::class,
        CredentialOAuthFinalizeTool::class,

        // Workflow (16)
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
        WorkflowNodeUpdateTool::class,
        WorkflowNodeAddTool::class,
        WorkflowNodeDeleteTool::class,
        WorkflowEdgeAddTool::class,
        WorkflowEdgeDeleteTool::class,
        WorkflowExportTool::class,
        WorkflowImportTool::class,

        // Project (14)
        ProjectListTool::class,
        ProjectGetTool::class,
        ProjectCreateTool::class,
        ProjectUpdateTool::class,
        ProjectActivateTool::class,
        ProjectPauseTool::class,
        ProjectResumeTool::class,
        ProjectRestartTool::class,
        ProjectTriggerRunTool::class,
        ProjectArchiveTool::class,
        ProjectScheduleManageTool::class,
        ProjectScheduleNlpTool::class,
        ProjectRunListTool::class,
        ProjectRunGetTool::class,

        // Approval (5)
        ApprovalListTool::class,
        ApprovalApproveTool::class,
        ApprovalRejectTool::class,
        ApprovalCompleteHumanTaskTool::class,
        ApprovalWebhookTool::class,

        // Signal (19)
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
        ImapMailboxTool::class,
        EmailReplyTool::class,
        ClearCueConnectorTool::class,
        SupabaseConnectorTool::class,
        ConnectorSubscriptionTool::class,
        IntentScoreTool::class,
        KgSearchTool::class,
        KgEntityFactsTool::class,
        KgAddFactTool::class,

        // Budget (3)
        BudgetSummaryTool::class,
        BudgetCheckTool::class,
        BudgetForecastTool::class,

        // Evaluation (2)
        EvaluationDatasetManageTool::class,
        EvaluationRunTool::class,

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

        // Knowledge Bases (5)
        KnowledgeBaseListTool::class,
        KnowledgeBaseCreateTool::class,
        KnowledgeBaseIngestTool::class,
        KnowledgeBaseSearchTool::class,
        KnowledgeBaseDeleteTool::class,

        // Memory (7)
        MemorySearchTool::class,
        MemoryListRecentTool::class,
        MemoryStatsTool::class,
        MemoryDeleteTool::class,
        MemoryUploadKnowledgeTool::class,
        MemoryAddTool::class,
        SupabaseProvisionMemoryTool::class,

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

        // Shared (12)
        NotificationTool::class,
        TeamGetTool::class,
        TeamUpdateTool::class,
        TeamMembersTool::class,
        LocalLlmTool::class,
        TeamByokCredentialManageTool::class,
        CustomEndpointManageTool::class,
        ApiTokenManageTool::class,
        TermsAcceptanceStatusTool::class,
        TermsAcceptanceHistoryTool::class,
        PushSubscriptionManageTool::class,
        PluginManageTool::class,

        // Telegram (1)
        TelegramBotTool::class,

        // Trigger (5)
        TriggerRuleListTool::class,
        TriggerRuleCreateTool::class,
        TriggerRuleUpdateTool::class,
        TriggerRuleDeleteTool::class,
        TriggerRuleTestTool::class,

        // Integration (6)
        IntegrationListTool::class,
        IntegrationConnectTool::class,
        IntegrationDisconnectTool::class,
        IntegrationPingTool::class,
        IntegrationExecuteTool::class,
        IntegrationCapabilitiesTool::class,

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

        // System (7)
        DashboardKpisTool::class,
        SystemHealthTool::class,
        SystemVersionCheckTool::class,
        AuditLogTool::class,
        GlobalSettingsUpdateTool::class,
        BlacklistManageTool::class,
        SecurityPolicyManageTool::class,

        // Chatbot (10)
        ChatbotListTool::class,
        ChatbotGetTool::class,
        ChatbotCreateTool::class,
        ChatbotUpdateTool::class,
        ChatbotDeleteTool::class,
        ChatbotToggleStatusTool::class,
        ChatbotTokenCreateTool::class,
        ChatbotTokenRevokeTool::class,
        ChatbotSessionListTool::class,
        ChatbotAnalyticsSummaryTool::class,
        ChatbotLearningEntriesListTool::class,

        // Bridge (7)
        BridgeStatusTool::class,
        BridgeListTool::class,
        BridgeEndpointListTool::class,
        BridgeEndpointToggleTool::class,
        BridgeDisconnectTool::class,
        BridgeRenameTool::class,
        BridgeSetRoutingTool::class,

        // Assistant (4)
        AssistantConversationListTool::class,
        AssistantConversationGetTool::class,
        AssistantSendMessageTool::class,
        AssistantConversationClearTool::class,

        // Feedback (2) — super admin only
        FeedbackListTool::class,
        FeedbackUpdateTool::class,

        // Git Repository (13)
        GitRepositoryListTool::class,
        GitRepositoryGetTool::class,
        GitRepositoryCreateTool::class,
        GitRepositoryUpdateTool::class,
        GitRepositoryDeleteTool::class,
        GitRepositoryTestTool::class,
        GitFileReadTool::class,
        GitFileWriteTool::class,
        GitFileListTool::class,
        GitBranchCreateTool::class,
        GitCommitTool::class,
        GitPullRequestCreateTool::class,
        GitPullRequestListTool::class,

        // Auth / Social Login (2)
        SocialAccountListTool::class,
        SocialAccountUnlinkTool::class,

        // Profile (5)
        ProfileGetTool::class,
        ProfileUpdateTool::class,
        // ProfilePasswordUpdateTool removed — password changes must not be callable by an LLM.
        // Prompt injection can trigger account takeover, especially for social-login users.
        ProfileTwoFactorStatusTool::class,
        ProfileConnectedAccountsTool::class,

        // Boruna (5)
        BorunaRunTool::class,
        BorunaValidateTool::class,
        BorunaEvidenceTool::class,
        BorunaCapabilityListTool::class,
        BorunaSkillManageTool::class,

        // Admin (7) — super admin only
        AdminTeamSuspendTool::class,
        AdminTeamBillingDetailTool::class,
        AdminBillingApplyCreditTool::class,
        AdminBillingRefundTool::class,
        AdminSecurityOverviewTool::class,
        AdminUserRevokeSessionsTool::class,
        AdminUserSendPasswordResetTool::class,
    ];

    /** @var array<int, class-string<Server\Resource>> */
    protected array $resources = [
        // MCP Apps UI resources — only exposed to clients that declare MCP Apps capability
        ApprovalsResource::class,
    ];
}
