<?php

namespace App\Mcp\Servers;

use App\Domain\Workflow\Models\Workflow;
use App\Mcp\Concerns\BootstrapsMcpAuth;
use App\Mcp\Resources\ApprovalsResource;
use App\Mcp\Tools\A2ui\A2uiComponentCatalogTool;
use App\Mcp\Tools\A2ui\A2uiRenderSurfaceTool;
use App\Mcp\Tools\A2ui\A2uiValidateSurfaceTool;
use App\Mcp\Tools\Admin\AdminBillingApplyCreditTool;
use App\Mcp\Tools\Admin\AdminBillingRefundTool;
use App\Mcp\Tools\Admin\AdminSecurityOverviewTool;
use App\Mcp\Tools\Admin\AdminTeamBillingDetailTool;
use App\Mcp\Tools\Admin\AdminTeamSuspendTool;
use App\Mcp\Tools\Admin\AdminUserRevokeSessionsTool;
use App\Mcp\Tools\Admin\AdminUserSendPasswordResetTool;
use App\Mcp\Tools\Agent\AgentCloneTool;
use App\Mcp\Tools\Agent\AgentConfigHistoryTool;
use App\Mcp\Tools\Agent\AgentConstraintTemplatesTool;
use App\Mcp\Tools\Agent\AgentCostsTool;
use App\Mcp\Tools\Agent\AgentCreateTool;
use App\Mcp\Tools\Agent\AgentDeleteTool;
use App\Mcp\Tools\Agent\AgentExecutionsListTool;
use App\Mcp\Tools\Agent\AgentFeedbackListTool;
use App\Mcp\Tools\Agent\AgentFeedbackStatsTool;
use App\Mcp\Tools\Agent\AgentFeedbackSubmitTool;
use App\Mcp\Tools\Agent\AgentGetTool;
use App\Mcp\Tools\Agent\AgentHeartbeatRunNowTool;
use App\Mcp\Tools\Agent\AgentHeartbeatUpdateTool;
use App\Mcp\Tools\Agent\AgentHookCreateTool;
use App\Mcp\Tools\Agent\AgentHookDeleteTool;
use App\Mcp\Tools\Agent\AgentHookListTool;
use App\Mcp\Tools\Agent\AgentHookToggleTool;
use App\Mcp\Tools\Agent\AgentListTool;
use App\Mcp\Tools\Agent\AgentResetSessionTool;
use App\Mcp\Tools\Agent\AgentRollbackConfigTool;
use App\Mcp\Tools\Agent\AgentRuntimeStateTool;
use App\Mcp\Tools\Agent\AgentSandboxTool;
use App\Mcp\Tools\Agent\AgentSetReasoningStrategyTool;
use App\Mcp\Tools\Agent\AgentSkillSyncTool;
use App\Mcp\Tools\Agent\AgentTemplatesListTool;
use App\Mcp\Tools\Agent\AgentToggleStatusTool;
use App\Mcp\Tools\Agent\AgentToolApprovalConfigureTool;
use App\Mcp\Tools\Agent\AgentToolSyncTool;
use App\Mcp\Tools\Agent\AgentUpdateIdentityTool;
use App\Mcp\Tools\Agent\AgentUpdateTool;
use App\Mcp\Tools\Agent\AgentWorkspaceExportTool;
use App\Mcp\Tools\Agent\AgentWorkspaceImportTool;
use App\Mcp\Tools\Approval\ApprovalApproveTool;
use App\Mcp\Tools\Approval\ApprovalCompleteHumanTaskTool;
use App\Mcp\Tools\Approval\ApprovalListTool;
use App\Mcp\Tools\Approval\ApprovalRejectTool;
use App\Mcp\Tools\Approval\ApprovalWebhookTool;
use App\Mcp\Tools\Approval\ListSecurityReviewsTool;
use App\Mcp\Tools\Approval\ResolveSecurityReviewTool;
use App\Mcp\Tools\Artifact\ArtifactContentTool;
use App\Mcp\Tools\Artifact\ArtifactDownloadTool;
use App\Mcp\Tools\Artifact\ArtifactGetTool;
use App\Mcp\Tools\Artifact\ArtifactListTool;
use App\Mcp\Tools\Assistant\AssistantAnnotateMessageTool;
use App\Mcp\Tools\Assistant\AssistantConversationClearTool;
use App\Mcp\Tools\Assistant\AssistantConversationCompactTool;
use App\Mcp\Tools\Assistant\AssistantConversationGetTool;
use App\Mcp\Tools\Assistant\AssistantConversationListTool;
use App\Mcp\Tools\Assistant\AssistantReviewConversationTool;
use App\Mcp\Tools\Assistant\AssistantSendMessageTool;
use App\Mcp\Tools\Auth\SocialAccountListTool;
use App\Mcp\Tools\Auth\SocialAccountUnlinkTool;
use App\Mcp\Tools\Boruna\BorunaCapabilityListTool;
use App\Mcp\Tools\Boruna\BorunaEvidenceTool;
use App\Mcp\Tools\Boruna\BorunaRunTool;
use App\Mcp\Tools\Boruna\BorunaSkillManageTool;
use App\Mcp\Tools\Boruna\BorunaValidateTool;
use App\Mcp\Tools\Bridge\BridgeConnectTool;
use App\Mcp\Tools\Bridge\BridgeDisconnectTool;
use App\Mcp\Tools\Bridge\BridgeEndpointListTool;
use App\Mcp\Tools\Bridge\BridgeEndpointToggleTool;
use App\Mcp\Tools\Bridge\BridgeListTool;
use App\Mcp\Tools\Bridge\BridgePingTool;
use App\Mcp\Tools\Bridge\BridgeRenameTool;
use App\Mcp\Tools\Bridge\BridgeSetRoutingTool;
use App\Mcp\Tools\Bridge\BridgeStatusTool;
use App\Mcp\Tools\Bridge\BridgeUpdateUrlTool;
use App\Mcp\Tools\Budget\BudgetAddCreditsTool;
use App\Mcp\Tools\Budget\BudgetCheckTool;
use App\Mcp\Tools\Budget\BudgetCostBreakdownTool;
use App\Mcp\Tools\Budget\BudgetForecastTool;
use App\Mcp\Tools\Budget\BudgetLedgerTool;
use App\Mcp\Tools\Budget\BudgetSummaryTool;
use App\Mcp\Tools\Budget\BudgetTransferTool;
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
use App\Mcp\Tools\Credential\CredentialListVersionsTool;
use App\Mcp\Tools\Credential\CredentialOAuthFinalizeTool;
use App\Mcp\Tools\Credential\CredentialOAuthInitiateTool;
use App\Mcp\Tools\Credential\CredentialRollbackTool;
use App\Mcp\Tools\Credential\CredentialRotateTool;
use App\Mcp\Tools\Credential\CredentialUpdateTool;
use App\Mcp\Tools\Crew\CrewActivateTool;
use App\Mcp\Tools\Crew\CrewBlackboardPostTool;
use App\Mcp\Tools\Crew\CrewBlackboardReadTool;
use App\Mcp\Tools\Crew\CrewCreateTool;
use App\Mcp\Tools\Crew\CrewDeleteTool;
use App\Mcp\Tools\Crew\CrewExecuteTool;
use App\Mcp\Tools\Crew\CrewExecutionPauseTool;
use App\Mcp\Tools\Crew\CrewExecutionResumeTool;
use App\Mcp\Tools\Crew\CrewExecutionsListTool;
use App\Mcp\Tools\Crew\CrewExecutionStatusTool;
use App\Mcp\Tools\Crew\CrewGenerateFromPromptTool;
use App\Mcp\Tools\Crew\CrewGetMessagesTool;
use App\Mcp\Tools\Crew\CrewGetTool;
use App\Mcp\Tools\Crew\CrewListTool;
use App\Mcp\Tools\Crew\CrewMemberRemoveTool;
use App\Mcp\Tools\Crew\CrewMemberUpdatePolicyTool;
use App\Mcp\Tools\Crew\CrewProposeRestructuringTool;
use App\Mcp\Tools\Crew\CrewSendMessageTool;
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
use App\Mcp\Tools\Evaluation\FlowEvaluationDatasetCreateTool;
use App\Mcp\Tools\Evaluation\FlowEvaluationResultsTool;
use App\Mcp\Tools\Evaluation\FlowEvaluationRunStartTool;
use App\Mcp\Tools\Evolution\EvolutionAnalyzeTool;
use App\Mcp\Tools\Evolution\EvolutionApplyTool;
use App\Mcp\Tools\Evolution\EvolutionApproveTool;
use App\Mcp\Tools\Evolution\EvolutionDeleteTool;
use App\Mcp\Tools\Evolution\EvolutionGetTool;
use App\Mcp\Tools\Evolution\EvolutionProposalListTool;
use App\Mcp\Tools\Evolution\EvolutionRejectTool;
use App\Mcp\Tools\Experiment\ExperimentContextHealthTool;
use App\Mcp\Tools\Experiment\ExperimentCostTool;
use App\Mcp\Tools\Experiment\ExperimentCreateTool;
use App\Mcp\Tools\Experiment\ExperimentGetTool;
use App\Mcp\Tools\Experiment\ExperimentKillTool;
use App\Mcp\Tools\Experiment\ExperimentListTool;
use App\Mcp\Tools\Experiment\ExperimentPauseTool;
use App\Mcp\Tools\Experiment\ExperimentResumeFromCheckpointTool;
use App\Mcp\Tools\Experiment\ExperimentResumeTool;
use App\Mcp\Tools\Experiment\ExperimentRetryFromStepTool;
use App\Mcp\Tools\Experiment\ExperimentRetryTool;
use App\Mcp\Tools\Experiment\ExperimentSearchHistoryTool;
use App\Mcp\Tools\Experiment\ExperimentShareTool;
use App\Mcp\Tools\Experiment\ExperimentSkipStageTool;
use App\Mcp\Tools\Experiment\ExperimentStageTelemetryTool;
use App\Mcp\Tools\Experiment\ExperimentStartTool;
use App\Mcp\Tools\Experiment\ExperimentStepsTool;
use App\Mcp\Tools\Experiment\ExperimentUpdateTool;
use App\Mcp\Tools\Experiment\ExperimentValidTransitionsTool;
use App\Mcp\Tools\Experiment\PlanWithKnowledgeTool;
use App\Mcp\Tools\Experiment\ReasoningBankSearchTool;
use App\Mcp\Tools\Experiment\UncertaintyEmitTool;
use App\Mcp\Tools\Experiment\UncertaintyResolveTool;
use App\Mcp\Tools\Experiment\WorkflowSnapshotListTool;
use App\Mcp\Tools\Experiment\WorklogAppendTool;
use App\Mcp\Tools\Experiment\WorklogReadTool;
use App\Mcp\Tools\Feedback\FeedbackListTool;
use App\Mcp\Tools\Feedback\FeedbackUpdateTool;
use App\Mcp\Tools\GitRepository\CodeCallChainTool;
use App\Mcp\Tools\GitRepository\CodeSearchTool;
use App\Mcp\Tools\GitRepository\CodeSkimFileTool;
use App\Mcp\Tools\GitRepository\CodeStructureTool;
use App\Mcp\Tools\GitRepository\ExperimentRepoMapTool;
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
use App\Mcp\Tools\Integration\ActivepiecesListPiecesTool;
use App\Mcp\Tools\Integration\ActivepiecesSyncTool;
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
use App\Mcp\Tools\Knowledge\KnowledgeListSourcesTool;
use App\Mcp\Tools\Knowledge\KnowledgeSyncNowTool;
use App\Mcp\Tools\Marketplace\MarketplaceAnalyticsTool;
use App\Mcp\Tools\Marketplace\MarketplaceBrowseTool;
use App\Mcp\Tools\Marketplace\MarketplaceCategoriesListTool;
use App\Mcp\Tools\Marketplace\MarketplaceInstallTool;
use App\Mcp\Tools\Marketplace\MarketplacePublishTool;
use App\Mcp\Tools\Marketplace\MarketplaceQualityReportTool;
use App\Mcp\Tools\Marketplace\MarketplaceReviewTool;
use App\Mcp\Tools\Marketplace\MarketplaceUnpublishTool;
use App\Mcp\Tools\Memory\MemoryAddTool;
use App\Mcp\Tools\Memory\MemoryDeleteTool;
use App\Mcp\Tools\Memory\MemoryExportTool;
use App\Mcp\Tools\Memory\MemoryGetTool;
use App\Mcp\Tools\Memory\MemoryListProposalsTool;
use App\Mcp\Tools\Memory\MemoryListRecentTool;
use App\Mcp\Tools\Memory\MemoryPromoteTool;
use App\Mcp\Tools\Memory\MemoryProposeTool;
use App\Mcp\Tools\Memory\MemorySearchTool;
use App\Mcp\Tools\Memory\MemoryStatsTool;
use App\Mcp\Tools\Memory\MemoryUnifiedSearchTool;
use App\Mcp\Tools\Memory\MemoryUpdateTool;
use App\Mcp\Tools\Memory\MemoryUploadKnowledgeTool;
use App\Mcp\Tools\Memory\SupabaseProvisionMemoryTool;
use App\Mcp\Tools\Outbound\ConnectorConfigDeleteTool;
use App\Mcp\Tools\Outbound\ConnectorConfigGetTool;
use App\Mcp\Tools\Outbound\ConnectorConfigListTool;
use App\Mcp\Tools\Outbound\ConnectorConfigSaveTool;
use App\Mcp\Tools\Outbound\ConnectorConfigTestTool;
use App\Mcp\Tools\Outbound\NtfySendTool;
use App\Mcp\Tools\Profile\ProfileConnectedAccountsTool;
use App\Mcp\Tools\Profile\ProfileGetTool;
use App\Mcp\Tools\Profile\ProfileTwoFactorStatusTool;
use App\Mcp\Tools\Profile\ProfileUpdateTool;
use App\Mcp\Tools\Project\ProjectActivateTool;
use App\Mcp\Tools\Project\ProjectArchiveTool;
use App\Mcp\Tools\Project\ProjectCancelRunTool;
use App\Mcp\Tools\Project\ProjectCloneTool;
use App\Mcp\Tools\Project\ProjectCreateTool;
use App\Mcp\Tools\Project\ProjectGetTool;
use App\Mcp\Tools\Project\ProjectHeartbeatConfigureTool;
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
use App\Mcp\Tools\RAGFlow\RagflowDatasetCreateTool;
use App\Mcp\Tools\RAGFlow\RagflowDatasetListTool;
use App\Mcp\Tools\RAGFlow\RagflowDocumentParseTool;
use App\Mcp\Tools\RAGFlow\RagflowDocumentUploadTool;
use App\Mcp\Tools\RAGFlow\RagflowKnowledgeGraphBuildTool;
use App\Mcp\Tools\RAGFlow\RagflowRaptorBuildTool;
use App\Mcp\Tools\RAGFlow\RagflowSearchTool;
use App\Mcp\Tools\RunPod\RunPodManageTool;
use App\Mcp\Tools\Shared\ApiTokenManageTool;
use App\Mcp\Tools\Shared\ContactHealthScoreTool;
use App\Mcp\Tools\Shared\CustomEndpointManageTool;
use App\Mcp\Tools\Shared\LocalLlmTool;
use App\Mcp\Tools\Shared\NotificationTool;
use App\Mcp\Tools\Shared\PluginManageTool;
use App\Mcp\Tools\Shared\PortkeyGatewayTool;
use App\Mcp\Tools\Shared\PushSubscriptionManageTool;
use App\Mcp\Tools\Shared\TeamAiFeaturesGetTool;
use App\Mcp\Tools\Shared\TeamAiFeaturesUpdateTool;
use App\Mcp\Tools\Shared\TeamByokCredentialManageTool;
use App\Mcp\Tools\Shared\TeamClaudeCodeVpsAccessTool;
use App\Mcp\Tools\Shared\TeamGetTool;
use App\Mcp\Tools\Shared\TeamInviteMemberTool;
use App\Mcp\Tools\Shared\TeamMembersTool;
use App\Mcp\Tools\Shared\TeamModelAllowlistTool;
use App\Mcp\Tools\Shared\TeamRemoveMemberTool;
use App\Mcp\Tools\Shared\TeamUpdateMemberRoleTool;
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
use App\Mcp\Tools\Signal\ForceReevaluateContactRiskTool;
use App\Mcp\Tools\Signal\GetContactRiskScoreTool;
use App\Mcp\Tools\Signal\HttpMonitorTool;
use App\Mcp\Tools\Signal\ImapMailboxTool;
use App\Mcp\Tools\Signal\InboundConnectorManageTool;
use App\Mcp\Tools\Signal\IntentScoreTool;
use App\Mcp\Tools\Signal\KgAddFactTool;
use App\Mcp\Tools\Signal\KgEdgeProvenanceTool;
use App\Mcp\Tools\Signal\KgEntityFactsTool;
use App\Mcp\Tools\Signal\KgGraphSearchTool;
use App\Mcp\Tools\Signal\KgInvalidateFactTool;
use App\Mcp\Tools\Signal\KgSearchTool;
use App\Mcp\Tools\Signal\ListHighRiskContactsTool;
use App\Mcp\Tools\Signal\SearxngSearchTool;
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
use App\Mcp\Tools\Skill\SkillAnnotateTool;
use App\Mcp\Tools\Skill\SkillAutoGenerateRunTool;
use App\Mcp\Tools\Skill\SkillBenchmarkCancelTool;
use App\Mcp\Tools\Skill\SkillBenchmarkListTool;
use App\Mcp\Tools\Skill\SkillBenchmarkStartTool;
use App\Mcp\Tools\Skill\SkillBenchmarkStatusTool;
use App\Mcp\Tools\Skill\SkillCloneTool;
use App\Mcp\Tools\Skill\SkillCreateTool;
use App\Mcp\Tools\Skill\SkillDegradationReportTool;
use App\Mcp\Tools\Skill\SkillDeleteTool;
use App\Mcp\Tools\Skill\SkillGenerateImprovementTool;
use App\Mcp\Tools\Skill\SkillGetTool;
use App\Mcp\Tools\Skill\SkillLineageTool;
use App\Mcp\Tools\Skill\SkillListTool;
use App\Mcp\Tools\Skill\SkillPlaygroundTestTool;
use App\Mcp\Tools\Skill\SkillQualityTool;
use App\Mcp\Tools\Skill\SkillSearchTool;
use App\Mcp\Tools\Skill\SkillUpdateTool;
use App\Mcp\Tools\Skill\SkillVersionsTool;
use App\Mcp\Tools\Skill\SupabaseEdgeFunctionSkillTool;
use App\Mcp\Tools\System\AuditLogTool;
use App\Mcp\Tools\System\BlacklistManageTool;
use App\Mcp\Tools\System\DashboardKpisTool;
use App\Mcp\Tools\System\GlobalSettingsUpdateTool;
use App\Mcp\Tools\System\LangfuseConfigTool;
use App\Mcp\Tools\System\MetricsAggregationsTool;
use App\Mcp\Tools\System\MetricsModelComparisonTool;
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
use App\Mcp\Tools\Tool\ToolFederationEnableTool;
use App\Mcp\Tools\Tool\ToolFederationGroupCreateTool;
use App\Mcp\Tools\Tool\ToolFederationGroupListTool;
use App\Mcp\Tools\Tool\ToolFederationStatusTool;
use App\Mcp\Tools\Tool\ToolGetTool;
use App\Mcp\Tools\Tool\ToolImportMcpTool;
use App\Mcp\Tools\Tool\ToolListTool;
use App\Mcp\Tools\Tool\ToolMiddlewareConfigTool;
use App\Mcp\Tools\Tool\ToolMiddlewareListTool;
use App\Mcp\Tools\Tool\ToolPoolListTool;
use App\Mcp\Tools\Tool\ToolProbeRemoteMcpTool;
use App\Mcp\Tools\Tool\ToolProfileListTool;
use App\Mcp\Tools\Tool\ToolSearchTool;
use App\Mcp\Tools\Tool\ToolSshFingerprintsTool;
use App\Mcp\Tools\Tool\ToolTemplateManageTool;
use App\Mcp\Tools\Tool\ToolUpdateTool;
use App\Mcp\Tools\Trigger\TriggerRuleCreateTool;
use App\Mcp\Tools\Trigger\TriggerRuleDeleteTool;
use App\Mcp\Tools\Trigger\TriggerRuleListTool;
use App\Mcp\Tools\Trigger\TriggerRuleTestTool;
use App\Mcp\Tools\Trigger\TriggerRuleUpdateTool;
use App\Mcp\Tools\VoiceSession\VoiceSessionCreateTool;
use App\Mcp\Tools\VoiceSession\VoiceSessionEndTool;
use App\Mcp\Tools\VoiceSession\VoiceSessionListTool;
use App\Mcp\Tools\VoiceSession\VoiceSessionTranscriptTool;
use App\Mcp\Tools\Webhook\WebhookCreateTool;
use App\Mcp\Tools\Webhook\WebhookDeleteTool;
use App\Mcp\Tools\Webhook\WebhookListTool;
use App\Mcp\Tools\Webhook\WebhookUpdateTool;
use App\Mcp\Tools\Website\WebsiteAnalyticsTool;
use App\Mcp\Tools\Website\WebsiteCreateTool;
use App\Mcp\Tools\Website\WebsiteDeleteTool;
use App\Mcp\Tools\Website\WebsiteDeploymentListTool;
use App\Mcp\Tools\Website\WebsiteDeployTool;
use App\Mcp\Tools\Website\WebsiteExportTool;
use App\Mcp\Tools\Website\WebsiteGenerateTool;
use App\Mcp\Tools\Website\WebsiteGetTool;
use App\Mcp\Tools\Website\WebsiteListTool;
use App\Mcp\Tools\Website\WebsitePageCreateTool;
use App\Mcp\Tools\Website\WebsitePageGetTool;
use App\Mcp\Tools\Website\WebsitePageListTool;
use App\Mcp\Tools\Website\WebsitePagePublishTool;
use App\Mcp\Tools\Website\WebsitePageUnpublishTool;
use App\Mcp\Tools\Website\WebsitePageUpdateTool;
use App\Mcp\Tools\Website\WebsiteUnpublishTool;
use App\Mcp\Tools\Website\WebsiteUpdateTool;
use App\Mcp\Tools\Workflow\WorkflowActivateTool;
use App\Mcp\Tools\Workflow\WorkflowCreateTool;
use App\Mcp\Tools\Workflow\WorkflowDeactivateTool;
use App\Mcp\Tools\Workflow\WorkflowDeleteTool;
use App\Mcp\Tools\Workflow\WorkflowDisableGatewayTool;
use App\Mcp\Tools\Workflow\WorkflowDuplicateTool;
use App\Mcp\Tools\Workflow\WorkflowEdgeAddTool;
use App\Mcp\Tools\Workflow\WorkflowEdgeDeleteTool;
use App\Mcp\Tools\Workflow\WorkflowEnableGatewayTool;
use App\Mcp\Tools\Workflow\WorkflowEstimateCostTool;
use App\Mcp\Tools\Workflow\WorkflowExecutionChainTool;
use App\Mcp\Tools\Workflow\WorkflowExportPolicyTool;
use App\Mcp\Tools\Workflow\WorkflowExportTool;
use App\Mcp\Tools\Workflow\WorkflowGatewayTool;
use App\Mcp\Tools\Workflow\WorkflowGenerateTool;
use App\Mcp\Tools\Workflow\WorkflowGetTool;
use App\Mcp\Tools\Workflow\WorkflowImportTool;
use App\Mcp\Tools\Workflow\WorkflowListGatewayToolsTool;
use App\Mcp\Tools\Workflow\WorkflowListTool;
use App\Mcp\Tools\Workflow\WorkflowNodeAddTool;
use App\Mcp\Tools\Workflow\WorkflowNodeDeleteTool;
use App\Mcp\Tools\Workflow\WorkflowNodeUpdateTool;
use App\Mcp\Tools\Workflow\WorkflowSaveGraphTool;
use App\Mcp\Tools\Workflow\WorkflowSetCompensationNodeTool;
use App\Mcp\Tools\Workflow\WorkflowSuggestionTool;
use App\Mcp\Tools\Workflow\WorkflowTimeGateTool;
use App\Mcp\Tools\Workflow\WorkflowUpdateTool;
use App\Mcp\Tools\Workflow\WorkflowValidateTool;
use Laravel\Mcp\Server;

class AgentFleetServer extends Server
{
    use BootstrapsMcpAuth;

    protected string $name = 'FleetQ';

    protected string $version = '1.13.0';

    // Return all tools in a single page — MCP clients like Claude.ai/Codex don't follow cursors
    public int $defaultPaginationLength = 300;

    public int $maxPaginationLength = 300;

    protected string $instructions = <<<'INSTRUCTIONS'
        FleetQ — AI Agent Mission Control Platform (Laravel 12, multi-tenant, event-driven pipeline)

        DOMAINS — use prefix to navigate to the right tool group:
        agent_*       AI agent CRUD, execution, config history, rollback, runtime state, templates
        experiment_*  Pipeline execution — 20-state machine: draft→scoring→planning→building→executing→completed/killed
        workflow_*    Visual DAG builder — node types: start/end/agent/conditional/human_task/switch/dynamic_fork/do_while
        project_*     Continuous & one-shot projects with scheduling and runs
        crew_*        Multi-agent team execution (hierarchical or sequential process)
        skill_*       Reusable AI building blocks: llm/connector/rule/hybrid/guardrail types
        tool_*        External tools: mcp_stdio/mcp_http/built_in (bash/filesystem/browser)
        credential_*  Encrypted service credentials: api_key/oauth2/bearer_token/basic_auth/custom
        approval_*    Human-in-the-loop: approve/reject decisions, human task forms, webhooks
        signal_*      Inbound signals: webhook/rss/manual connectors, contacts, intent scoring
        budget_*      Credit tracking (1 credit = $0.001 USD), forecast, check before execution
        memory_*      Persistent semantic memory with vector search across agent sessions
        artifact_*    Versioned outputs from experiments, crews, and projects
        outbound_*    Delivery channels: email, Telegram, Slack, webhook
        trigger_*     Event-driven automation rules with condition evaluator
        integration_* Third-party service connections with capability discovery
        marketplace_* Browse, publish, and install skills/agents/workflows
        bridge_*      Local agent relay — connects Claude Code, Codex, Kiro on remote machines
        kg_*          Knowledge graph: entities, typed facts, semantic search (signal_* group)
        evolution_*   AI-suggested agent improvement proposals — apply or reject
        system_*      Health, KPI dashboard, audit log, global settings
        email_*       Email templates + themes with AI generation
        chatbot_*     Embeddable chat widgets with session analytics and learning
        git_*         Git repository management for agent codebases
        team_*        Members, BYOK LLM keys, API tokens, team settings
        assistant_*   Conversational AI assistant — send messages, manage conversations
        voice_session_* LiveKit real-time voice sessions — create/list/end/transcript

        SEQUENCING — order matters:
        1. Before executing: call budget_check — experiments and crews consume credits
        2. Experiments: call experiment_valid_transitions before experiment_transition to see allowed next states
        3. Workflows: must have status="active" before execution — call workflow_activate first if draft/archived
        4. Agents: must have status="active" to run — call agent_toggle_status to enable if disabled
        5. Human tasks: read the form_schema from approval_list first, then approval_complete_human_task
        6. Credentials: never returned in full after creation — use credential_rotate to update secrets

        CONSTRAINTS:
        - All operations are scoped to the authenticated team (team_id is implicit — never pass it)
        - All IDs are UUID v7 strings (e.g. "018e4b2a-7c3f-7000-9b1a-000000000001")
        - Pagination: limit (default 20, max 100) + cursor (opaque token from previous response's next_cursor)
        - Experiment states are a strict state machine — invalid transitions return a validation error
        - Credential secret values are write-only — read operations never return secret data

        SELF-CORRECTION:
        - On tool errors: read the returned message field — it contains corrective guidance
        - Experiment stuck: use experiment_valid_transitions to see what transitions are currently allowed
        - Budget error: call budget_check to inspect remaining credits and reservation state
        - Not sure which tool: start with {domain}_list to orient, then {domain}_get for details
        INSTRUCTIONS;

    protected function boot(): void
    {
        $this->bootstrapMcpAuth();

        // Append plugin-contributed MCP tools (registered via FleetPluginServiceProvider::$mcpTools)
        foreach (app('fleet.mcp.tool_classes') as $toolClass) {
            if (! in_array($toolClass, $this->tools, true)) {
                $this->tools[] = $toolClass;
            }
        }

        // Dynamically register each MCP-exposed workflow as a named gateway tool.
        // ServerContext supports both class-string and Tool instances.
        try {
            $exposed = Workflow::withoutGlobalScopes()
                ->where('mcp_exposed', true)
                ->whereNotNull('mcp_tool_name')
                ->get();

            foreach ($exposed as $workflow) {
                $this->tools[] = app(WorkflowGatewayTool::class, ['workflow' => $workflow]);
            }
        } catch (\Throwable) {
            // Silently skip if DB is unavailable (e.g. during migrations or tests)
        }
    }

    protected array $tools = [
        // Agent (23)
        AgentListTool::class,
        AgentGetTool::class,
        AgentCreateTool::class,
        AgentUpdateTool::class,
        AgentToggleStatusTool::class,
        AgentSetReasoningStrategyTool::class,
        AgentTemplatesListTool::class,
        AgentConstraintTemplatesTool::class,
        AgentSkillSyncTool::class,
        AgentToolSyncTool::class,
        AgentFeedbackSubmitTool::class,
        AgentFeedbackListTool::class,
        AgentFeedbackStatsTool::class,
        AgentDeleteTool::class,
        AgentConfigHistoryTool::class,
        AgentRollbackConfigTool::class,
        AgentRuntimeStateTool::class,
        AgentResetSessionTool::class,
        AgentSandboxTool::class,
        AgentHeartbeatUpdateTool::class,
        AgentHeartbeatRunNowTool::class,
        AgentHookListTool::class,
        AgentHookCreateTool::class,
        AgentHookToggleTool::class,
        AgentHookDeleteTool::class,
        AgentUpdateIdentityTool::class,
        AgentToolApprovalConfigureTool::class,
        AgentWorkspaceExportTool::class,
        AgentWorkspaceImportTool::class,
        AgentCloneTool::class,
        AgentExecutionsListTool::class,
        AgentCostsTool::class,

        // Evolution (7)
        EvolutionProposalListTool::class,
        EvolutionAnalyzeTool::class,
        EvolutionApproveTool::class,
        EvolutionApplyTool::class,
        EvolutionRejectTool::class,
        EvolutionGetTool::class,
        EvolutionDeleteTool::class,

        // Crew (16)
        CrewListTool::class,
        CrewGetTool::class,
        CrewCreateTool::class,
        CrewUpdateTool::class,
        CrewExecuteTool::class,
        CrewExecutionStatusTool::class,
        CrewExecutionPauseTool::class,
        CrewExecutionResumeTool::class,
        CrewExecutionsListTool::class,
        CrewSendMessageTool::class,
        CrewGetMessagesTool::class,
        CrewMemberUpdatePolicyTool::class,
        CrewGenerateFromPromptTool::class,
        CrewProposeRestructuringTool::class,
        CrewActivateTool::class,
        CrewDeleteTool::class,
        CrewMemberRemoveTool::class,
        CrewBlackboardPostTool::class,
        CrewBlackboardReadTool::class,

        // Experiment (16)
        ExperimentListTool::class,
        ExperimentGetTool::class,
        ExperimentCreateTool::class,
        ExperimentStartTool::class,
        ExperimentPauseTool::class,
        ExperimentResumeTool::class,
        ExperimentRetryTool::class,
        ExperimentRetryFromStepTool::class,
        ExperimentResumeFromCheckpointTool::class,
        ExperimentStageTelemetryTool::class,
        ExperimentKillTool::class,
        ExperimentValidTransitionsTool::class,
        ExperimentCostTool::class,
        ExperimentStepsTool::class,
        ExperimentShareTool::class,
        ExperimentSearchHistoryTool::class,
        ExperimentContextHealthTool::class,
        ExperimentSkipStageTool::class,
        ExperimentUpdateTool::class,
        WorkflowSnapshotListTool::class,
        UncertaintyEmitTool::class,
        UncertaintyResolveTool::class,
        WorklogAppendTool::class,
        WorklogReadTool::class,
        PlanWithKnowledgeTool::class,
        ReasoningBankSearchTool::class,

        // Skill (20)
        SkillListTool::class,
        SkillGetTool::class,
        SkillCreateTool::class,
        SkillUpdateTool::class,
        SkillDeleteTool::class,
        SkillVersionsTool::class,
        SkillQualityTool::class,
        SkillSearchTool::class,
        SkillLineageTool::class,
        SkillDegradationReportTool::class,
        GuardrailTool::class,
        MultiModelConsensusTool::class,
        CodeExecutionTool::class,
        BrowserSkillTool::class,
        SupabaseEdgeFunctionSkillTool::class,
        SkillPlaygroundTestTool::class,
        SkillAnnotateTool::class,
        SkillGenerateImprovementTool::class,
        SkillBenchmarkStartTool::class,
        SkillBenchmarkStatusTool::class,
        SkillBenchmarkCancelTool::class,
        SkillBenchmarkListTool::class,
        SkillAutoGenerateRunTool::class,
        SkillCloneTool::class,

        // Tool (19)
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
        ToolPoolListTool::class,
        ToolFederationStatusTool::class,
        ToolFederationEnableTool::class,
        ToolFederationGroupListTool::class,
        ToolFederationGroupCreateTool::class,
        ToolProfileListTool::class,
        ToolSearchTool::class,
        ToolMiddlewareListTool::class,
        ToolMiddlewareConfigTool::class,
        ToolTemplateManageTool::class,

        // Credential (10)
        CredentialListTool::class,
        CredentialGetTool::class,
        CredentialCreateTool::class,
        CredentialUpdateTool::class,
        CredentialDeleteTool::class,
        CredentialRotateTool::class,
        CredentialOAuthInitiateTool::class,
        CredentialOAuthFinalizeTool::class,
        CredentialListVersionsTool::class,
        CredentialRollbackTool::class,

        // Workflow (21)
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
        WorkflowExportPolicyTool::class,
        WorkflowEnableGatewayTool::class,
        WorkflowDisableGatewayTool::class,
        WorkflowListGatewayToolsTool::class,
        WorkflowSetCompensationNodeTool::class,
        WorkflowDeactivateTool::class,
        WorkflowDeleteTool::class,

        // Project (16)
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
        ProjectHeartbeatConfigureTool::class,
        ProjectRunListTool::class,
        ProjectRunGetTool::class,
        ProjectCancelRunTool::class,
        ProjectCloneTool::class,

        // Approval (5)
        ApprovalListTool::class,
        ApprovalApproveTool::class,
        ApprovalRejectTool::class,
        ApprovalCompleteHumanTaskTool::class,
        ApprovalWebhookTool::class,
        ListSecurityReviewsTool::class,
        ResolveSecurityReviewTool::class,

        // Signal (16)
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
        GetContactRiskScoreTool::class,
        ListHighRiskContactsTool::class,
        ForceReevaluateContactRiskTool::class,
        ImapMailboxTool::class,
        EmailReplyTool::class,
        ClearCueConnectorTool::class,
        SupabaseConnectorTool::class,
        ConnectorSubscriptionTool::class,
        IntentScoreTool::class,

        // Web Search (1)
        SearxngSearchTool::class,

        // KnowledgeGraph (6)
        KgSearchTool::class,
        KgEntityFactsTool::class,
        KgAddFactTool::class,
        KgInvalidateFactTool::class,
        KgGraphSearchTool::class,
        KgEdgeProvenanceTool::class,

        // Budget (7)
        BudgetSummaryTool::class,
        BudgetCheckTool::class,
        BudgetForecastTool::class,
        BudgetLedgerTool::class,
        BudgetAddCreditsTool::class,
        BudgetTransferTool::class,
        BudgetCostBreakdownTool::class,

        // Evaluation (5)
        EvaluationDatasetManageTool::class,
        EvaluationRunTool::class,
        FlowEvaluationDatasetCreateTool::class,
        FlowEvaluationRunStartTool::class,
        FlowEvaluationResultsTool::class,

        // Cache (2)
        SemanticCacheStatsTool::class,
        SemanticCachePurgeTool::class,

        // Marketplace (8)
        MarketplaceBrowseTool::class,
        MarketplacePublishTool::class,
        MarketplaceInstallTool::class,
        MarketplaceAnalyticsTool::class,
        MarketplaceReviewTool::class,
        MarketplaceCategoriesListTool::class,
        MarketplaceQualityReportTool::class,
        MarketplaceUnpublishTool::class,

        // Knowledge Bases (5)
        KnowledgeBaseListTool::class,
        KnowledgeBaseCreateTool::class,
        KnowledgeBaseIngestTool::class,
        KnowledgeBaseSearchTool::class,
        KnowledgeBaseDeleteTool::class,

        // RAGFlow — deep document understanding & hybrid retrieval (7)
        RagflowDatasetCreateTool::class,
        RagflowDatasetListTool::class,
        RagflowDocumentUploadTool::class,
        RagflowDocumentParseTool::class,
        RagflowSearchTool::class,
        RagflowKnowledgeGraphBuildTool::class,
        RagflowRaptorBuildTool::class,

        // Memory (14)
        MemorySearchTool::class,
        MemoryUnifiedSearchTool::class,
        MemoryListRecentTool::class,
        MemoryStatsTool::class,
        MemoryDeleteTool::class,
        MemoryUploadKnowledgeTool::class,
        MemoryAddTool::class,
        SupabaseProvisionMemoryTool::class,
        MemoryProposeTool::class,
        MemoryPromoteTool::class,
        MemoryListProposalsTool::class,
        MemoryUpdateTool::class,
        MemoryExportTool::class,
        MemoryGetTool::class,

        // Knowledge Ingestion (2)
        KnowledgeListSourcesTool::class,
        KnowledgeSyncNowTool::class,

        // Artifact (4)
        ArtifactListTool::class,
        ArtifactGetTool::class,
        ArtifactContentTool::class,
        ArtifactDownloadTool::class,

        // Outbound (6)
        ConnectorConfigListTool::class,
        ConnectorConfigGetTool::class,
        ConnectorConfigSaveTool::class,
        ConnectorConfigDeleteTool::class,
        ConnectorConfigTestTool::class,
        NtfySendTool::class,

        // Webhook (4)
        WebhookListTool::class,
        WebhookCreateTool::class,
        WebhookUpdateTool::class,
        WebhookDeleteTool::class,

        // Shared (19)
        ContactHealthScoreTool::class,
        NotificationTool::class,
        TeamGetTool::class,
        TeamUpdateTool::class,
        TeamMembersTool::class,
        TeamAiFeaturesGetTool::class,
        TeamAiFeaturesUpdateTool::class,
        TeamModelAllowlistTool::class,
        LocalLlmTool::class,
        TeamByokCredentialManageTool::class,
        TeamClaudeCodeVpsAccessTool::class,
        PortkeyGatewayTool::class,
        CustomEndpointManageTool::class,
        ApiTokenManageTool::class,
        TermsAcceptanceStatusTool::class,
        TermsAcceptanceHistoryTool::class,
        PushSubscriptionManageTool::class,
        PluginManageTool::class,
        TeamInviteMemberTool::class,
        TeamUpdateMemberRoleTool::class,
        TeamRemoveMemberTool::class,

        // Telegram (1)
        TelegramBotTool::class,

        // Trigger (5)
        TriggerRuleListTool::class,
        TriggerRuleCreateTool::class,
        TriggerRuleUpdateTool::class,
        TriggerRuleDeleteTool::class,
        TriggerRuleTestTool::class,

        // Integration (8)
        IntegrationListTool::class,
        IntegrationConnectTool::class,
        IntegrationDisconnectTool::class,
        IntegrationPingTool::class,
        IntegrationExecuteTool::class,
        IntegrationCapabilitiesTool::class,
        ActivepiecesSyncTool::class,
        ActivepiecesListPiecesTool::class,

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

        // System (10)
        DashboardKpisTool::class,
        SystemHealthTool::class,
        SystemVersionCheckTool::class,
        AuditLogTool::class,
        GlobalSettingsUpdateTool::class,
        BlacklistManageTool::class,
        SecurityPolicyManageTool::class,
        LangfuseConfigTool::class,
        MetricsAggregationsTool::class,
        MetricsModelComparisonTool::class,

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

        // Bridge (10)
        BridgeStatusTool::class,
        BridgeListTool::class,
        BridgeConnectTool::class,
        BridgePingTool::class,
        BridgeUpdateUrlTool::class,
        BridgeEndpointListTool::class,
        BridgeEndpointToggleTool::class,
        BridgeDisconnectTool::class,
        BridgeRenameTool::class,
        BridgeSetRoutingTool::class,

        // Assistant (5)
        AssistantConversationListTool::class,
        AssistantConversationGetTool::class,
        AssistantSendMessageTool::class,
        AssistantConversationClearTool::class,
        AssistantConversationCompactTool::class,
        AssistantAnnotateMessageTool::class,
        AssistantReviewConversationTool::class,

        // Feedback (2) — super admin only
        FeedbackListTool::class,
        FeedbackUpdateTool::class,

        // Git Repository (17)
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
        CodeSearchTool::class,
        CodeStructureTool::class,
        CodeCallChainTool::class,
        CodeSkimFileTool::class,
        ExperimentRepoMapTool::class,

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

        // Voice Sessions (4) — LiveKit real-time voice agent sessions
        VoiceSessionListTool::class,
        VoiceSessionCreateTool::class,
        VoiceSessionEndTool::class,
        VoiceSessionTranscriptTool::class,

        // A2UI (3) — Agent-to-UI protocol rendering
        A2uiComponentCatalogTool::class,
        A2uiRenderSurfaceTool::class,
        A2uiValidateSurfaceTool::class,

        // Website (15)
        WebsiteListTool::class,
        WebsiteGetTool::class,
        WebsiteCreateTool::class,
        WebsiteUpdateTool::class,
        WebsiteDeleteTool::class,
        WebsiteUnpublishTool::class,
        WebsitePageListTool::class,
        WebsitePageGetTool::class,
        WebsitePageCreateTool::class,
        WebsitePageUpdateTool::class,
        WebsitePagePublishTool::class,
        WebsitePageUnpublishTool::class,
        WebsiteGenerateTool::class,
        WebsiteExportTool::class,
        WebsiteAnalyticsTool::class,
        WebsiteDeployTool::class,
        WebsiteDeploymentListTool::class,
    ];

    /** @var array<int, class-string<Server\Resource>> */
    protected array $resources = [
        // MCP Apps UI resources — only exposed to clients that declare MCP Apps capability
        ApprovalsResource::class,
    ];
}
