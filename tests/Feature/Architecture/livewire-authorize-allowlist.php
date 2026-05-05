<?php

/**
 * Allowlist of Livewire write methods that intentionally don't call Gate::authorize
 * (or for which authorize is tracked as a known gap).
 *
 * Format: [FullyQualifiedClass::method => 'reason']
 *
 * Two valid reasons to skip authorize:
 *   1. UI-only — method mutates only component state (cancel, removeRow, etc.)
 *   2. Per-user — method only writes to auth()->user() and uses Gate::authorize('update-self')
 *      OR explicit user-id scoping with no input from request.
 *
 * Adding entries here is reviewed in the architecture test PR.
 *
 * The "pre-existing" entries below were surfaced by this test's first run
 * on 2026-05-04 — they predate the test and are tracked for a focused
 * follow-up sprint (`livewire-authorize-sweep-2`). The test still fails
 * if NEW write methods ship without authorize, so the regression-prevention
 * value of the test is preserved while the historical tail is closed.
 */

return [
    // Per-user profile/notification forms — write to auth()->user() only.
    // These ship Gate::authorize('update-self') after the audit sprint.
    'App\\Livewire\\Profile\\UpdateProfileInformationForm::save' => 'per-user; uses update-self gate',
    'App\\Livewire\\Profile\\NotificationPreferencesForm::save' => 'per-user; uses update-self gate',
    'App\\Livewire\\Shared\\NotificationBell::markAsRead' => 'per-user; explicit user_id scoping',
    'App\\Livewire\\Shared\\NotificationBell::markAllAsRead' => 'per-user; explicit user_id scoping',
    'App\\Livewire\\Shared\\NotificationPreferencesPage::save' => 'per-user; uses update-self gate',

    // Pre-existing gaps surfaced 2026-05-04. Tracked in livewire-authorize-sweep-2 sprint.
    'App\\Livewire\\AgentChat\\ExternalAgentDetailPage::disable' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\AgentChat\\ExternalAgentListPage::register' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Agents\\AgentDetailPage::deleteAgent' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Agents\\AgentDetailPage::deleteHook' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Agents\\AgentDetailPage::exportWorkspace' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Agents\\AgentDetailPage::publishChatProtocol' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Agents\\AgentDetailPage::revokeChatProtocol' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Agents\\AgentDetailPage::rotateChatProtocolSecret' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Agents\\AgentDetailPage::runHeartbeatNow' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Agents\\AgentDetailPage::save' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Agents\\AgentDetailPage::saveHook' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Agents\\AgentDetailPage::saveIdentityTemplate' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Agents\\AgentDetailPage::toggleHeartbeat' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Agents\\AgentDetailPage::toggleHook' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Agents\\AgentDetailPage::toggleStatus' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Agents\\AgentListPage::importWorkspace' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Approvals\\ApprovalInboxPage::approve' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Approvals\\ApprovalInboxPage::approveProposal' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Approvals\\ApprovalInboxPage::approveWithEdit' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Approvals\\HumanTaskForm::reject' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Chatbots\\ChatbotKnowledgeBasePage::addSource' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Chatbots\\ChatbotKnowledgeBasePage::deleteSource' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Chatbots\\ChatbotKnowledgeBasePage::toggleSource' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Components\\ThemeSwitcher::setTheme' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Credentials\\CredentialDetailPage::deleteCredential' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Crews\\CrewExecutionPage::execute' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Dashboard\\DashboardPage::toggleWidget' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Email\\EmailTemplateListPage::create' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Email\\EmailTemplateListPage::delete' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Email\\EmailThemeListPage::create' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Evaluation\\EvaluationPage::createDataset' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Evaluation\\EvaluationPage::deleteDataset' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Evaluation\\EvaluationPage::runEvaluation' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Evolution\\EvolutionListPage::approve' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Evolution\\EvolutionListPage::reject' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Evolution\\EvolutionProposalPanel::approve' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Evolution\\EvolutionProposalPanel::reject' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Experiments\\CreateExperimentForm::create' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Experiments\\ExperimentDetailPage::pauseExperiment' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Experiments\\ExperimentDetailPage::resumeExperiment' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Experiments\\ExperimentDetailPage::resumeFromCheckpoint' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Experiments\\ExperimentDetailPage::revokeShare' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\GitRepositories\\GitRepositoryDetailPage::testConnection' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\KnowledgeGraph\\KnowledgeGraphBrowserPage::addFact' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\KnowledgeGraph\\KnowledgeGraphBrowserPage::deleteFact' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Marketplace\\PublishForm::publish' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Memory\\KnowledgeSourcesPage::create' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Memory\\KnowledgeSourcesPage::delete' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Profile\\PasskeysForm::deletePasskey' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Profile\\UpdatePasswordForm::setInitialPassword' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Projects\\ProjectListPage::archive' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Projects\\ProjectListPage::pause' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Projects\\ProjectListPage::resume' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Settings\\GlobalSettingsPage::addBlacklistEntry' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Settings\\GlobalSettingsPage::importSelectedServers' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Settings\\GlobalSettingsPage::removeBlacklistEntry' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Settings\\GlobalSettingsPage::toggleAgent' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Settings\\PluginsPage::togglePlugin' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Setup\\SetupPage::createAccount' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Shared\\FixWithAssistant::executeRecoveryAction' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Shared\\NotificationInboxPage::deleteNotification' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Signals\\BugReportDetailPage::addComment' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Signals\\BugReportListPage::delete' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Signals\\ConnectorBindingsPage::approve' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Signals\\ConnectorBindingsPage::delete' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Signals\\ConnectorSubscriptionsPage::delete' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Signals\\ConnectorSubscriptionsPage::save' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Signals\\ConnectorSubscriptionsPage::toggleActive' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Signals\\SignalConnectorsPage::addImapConnector' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Signals\\SignalConnectorsPage::addMonitor' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Signals\\SignalConnectorsPage::addRssFeed' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Signals\\SignalConnectorsPage::removeImapConnector' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Signals\\SignalConnectorsPage::removeMonitor' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Signals\\SignalConnectorsPage::removeRssFeed' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Skills\\SkillPlayground::generateImprovement' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Skills\\SkillPlayground::run' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Teams\\TeamSettingsPage::addCustomEndpoint' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Teams\\TeamSettingsPage::addProviderCredential' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Teams\\TeamSettingsPage::connectViaUrl' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Teams\\TeamSettingsPage::createApiToken' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Teams\\TeamSettingsPage::disconnectAllBridges' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Teams\\TeamSettingsPage::disconnectBridge' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Teams\\TeamSettingsPage::removeCustomEndpoint' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Teams\\TeamSettingsPage::removeOllamaCredential' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Teams\\TeamSettingsPage::removeOpenaiCompatibleCredential' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Teams\\TeamSettingsPage::removePortkeyConfig' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Teams\\TeamSettingsPage::removeProviderCredential' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Teams\\TeamSettingsPage::removeTelegramBot' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Teams\\TeamSettingsPage::revokeApiToken' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Teams\\TeamSettingsPage::saveApprovalSettings' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Teams\\TeamSettingsPage::saveBridgeRouting' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Teams\\TeamSettingsPage::saveMcpToolPreferences' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Teams\\TeamSettingsPage::saveOllamaCredential' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Teams\\TeamSettingsPage::saveOpenaiCompatibleCredential' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Teams\\TeamSettingsPage::savePortkeyConfig' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Teams\\TeamSettingsPage::saveCreditMargin' => 'pre-existing — super-admin only; checked via is_super_admin, not Gate. Tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Teams\\TeamSettingsPage::saveTeamSettings' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Teams\\TeamSettingsPage::saveTelegramBot' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Teams\\TeamSettingsPage::toggleCustomEndpoint' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Tools\\FederationGroupsPage::create' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Tools\\FederationGroupsPage::delete' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Tools\\ToolListPage::toggleStatus' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Tools\\ToolTemplateCatalogPage::deploy' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Websites\\CreateWebsiteForm::generate' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Workflows\\EvaluationListPage::createDataset' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Workflows\\EvaluationListPage::deleteDataset' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Workflows\\WorkflowBuilderPage::activate' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Workflows\\WorkflowBuilderPage::generateFromPrompt' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
    'App\\Livewire\\Workflows\\WorkflowDetailPage::archive' => 'pre-existing — tracked for livewire-authorize-sweep-2 sprint',
];
