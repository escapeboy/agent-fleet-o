<?php

use App\Http\Controllers\AgentCardController;
use App\Http\Controllers\Api\V1\AgentManifestController;
use App\Http\Controllers\ArtifactPreviewController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\EmailTemplatePreviewController;
use App\Http\Controllers\IntegrationOAuthController;
use App\Http\Controllers\MarketplacePageController;
use App\Http\Controllers\PublicExperimentController;
use App\Http\Controllers\SocialAuthController;
use App\Http\Controllers\UseCasesController;
use App\Http\Controllers\WebsiteDeploymentDownloadController;
use App\Http\Controllers\WebsitePagePreviewController;
use App\Http\Controllers\WellKnownFleetQController;
use App\Http\Middleware\BypassAuth;
use App\Http\Middleware\EnsureTermsAccepted;
use App\Http\Middleware\SetCurrentTeam;
use App\Http\Middleware\SetPostgresRlsContext;
use App\Livewire\Admin\AiControlCenterPage;
use App\Livewire\AgentChat\AgentverseBrowsePage;
use App\Livewire\AgentChat\ExternalAgentDetailPage;
use App\Livewire\AgentChat\ExternalAgentListPage;
use App\Livewire\Agents\AgentDetailPage;
use App\Livewire\Agents\AgentListPage;
use App\Livewire\Agents\AgentTemplateGalleryPage;
use App\Livewire\Agents\CreateAgentForm;
use App\Livewire\Agents\QuickAgentForm;
use App\Livewire\Agents\VoiceSessionPage;
use App\Livewire\Approvals\ApprovalInboxPage;
use App\Livewire\AuditConsole\AuditConsoleDetailPage;
use App\Livewire\AuditConsole\AuditConsoleListPage;
use App\Livewire\AuditConsole\AuditConsoleSettingsPage;
use App\Livewire\Audit\AuditLogPage;
use App\Livewire\Auth\AcceptTermsPage;
use App\Livewire\Changelog\ChangelogPage;
use App\Livewire\Chatbots\ChatbotAnalyticsPage;
use App\Livewire\Chatbots\ChatbotConversationListPage;
use App\Livewire\Chatbots\ChatbotDetailPage;
use App\Livewire\Chatbots\ChatbotKnowledgeBasePage;
use App\Livewire\Chatbots\ChatbotListPage;
use App\Livewire\Chatbots\CreateChatbotForm;
use App\Livewire\Credentials\CreateCredentialForm;
use App\Livewire\Credentials\CredentialDetailPage;
use App\Livewire\Credentials\CredentialListPage;
use App\Livewire\Crews\CreateCrewForm;
use App\Livewire\Crews\CrewDetailPage;
use App\Livewire\Crews\CrewExecutionPage;
use App\Livewire\Crews\CrewListPage;
use App\Livewire\Dashboard\DashboardPage;
use App\Livewire\Email\EmailTemplateBuilderPage;
use App\Livewire\Email\EmailTemplateListPage;
use App\Livewire\Email\EmailThemeDetailPage;
use App\Livewire\Email\EmailThemeListPage;
use App\Livewire\Evaluation\EvaluationCompareRunsPage;
use App\Livewire\Evaluation\EvaluationPage;
use App\Livewire\Evolution\EvolutionListPage;
use App\Livewire\Experiments\ExperimentDetailPage;
use App\Livewire\Experiments\ExperimentListPage;
use App\Livewire\Frameworks\FrameworksBrowsePage;
use App\Livewire\GitRepositories\CreateGitRepositoryForm;
use App\Livewire\GitRepositories\GitRepositoryDetailPage;
use App\Livewire\GitRepositories\GitRepositoryListPage;
use App\Livewire\Health\HealthPage;
use App\Livewire\Insights\InsightsPage;
use App\Livewire\Integrations\EditIntegrationForm;
use App\Livewire\Integrations\IntegrationDetailPage;
use App\Livewire\Integrations\IntegrationListPage;
use App\Livewire\KnowledgeGraph\KnowledgeGraphBrowserPage;
use App\Livewire\Marketplace\MarketplaceBrowsePage;
use App\Livewire\Marketplace\MarketplaceDetailPage;
use App\Livewire\Marketplace\PublishForm;
use App\Livewire\Memory\KnowledgeSourcesPage;
use App\Livewire\Memory\MemoryBrowserPage;
use App\Livewire\Metrics\AiRoutingPage;
use App\Livewire\Metrics\ModelComparisonPage;
use App\Livewire\OutboundConnectors\NotificationOutboundPage;
use App\Livewire\OutboundConnectors\OutboundConnectorsPage;
use App\Livewire\OutboundConnectors\WebhookOutboundPage;
use App\Livewire\OutboundConnectors\WhatsAppOutboundPage;
use App\Livewire\Profile\ProfilePage;
use App\Livewire\Projects\CreateProjectForm as CreateProjectFormPage;
use App\Livewire\Projects\EditProjectForm;
use App\Livewire\Projects\ProjectDetailPage;
use App\Livewire\Projects\ProjectKanbanPage;
use App\Livewire\Projects\ProjectListPage;
use App\Livewire\Settings\GlobalSettingsPage;
use App\Livewire\Settings\PluginsPage;
use App\Livewire\Setup\SetupPage;
use App\Livewire\Shared\NotificationInboxPage;
use App\Livewire\Shared\NotificationPreferencesPage;
use App\Livewire\Signals\BugReportDetailPage;
use App\Livewire\Signals\BugReportListPage;
use App\Livewire\Signals\ConnectorBindingsPage;
use App\Livewire\Signals\ConnectorSubscriptionsPage;
use App\Livewire\Signals\ContactDetailPage;
use App\Livewire\Signals\ContactsPage;
use App\Livewire\Signals\EntityBrowserPage;
use App\Livewire\Signals\ManualSignalForm;
use App\Livewire\Signals\SignalBrowserPage;
use App\Livewire\Signals\SignalConnectorsPage;
use App\Livewire\Skills\CreateSkillForm;
use App\Livewire\Skills\SkillDetailPage;
use App\Livewire\Skills\SkillListPage;
use App\Livewire\Teams\TeamSettingsPage;
use App\Livewire\Telegram\TelegramBotsPage;
use App\Livewire\Tools\CreateToolForm;
use App\Livewire\Tools\FederationGroupsPage;
use App\Livewire\Tools\McpMarketplacePage;
use App\Livewire\Tools\ToolDetailPage;
use App\Livewire\Tools\ToolListPage;
use App\Livewire\Tools\ToolSearchHistoryPage;
use App\Livewire\Tools\ToolTemplateCatalogPage;
use App\Livewire\Triggers\CreateTriggerRuleForm;
use App\Livewire\Triggers\TriggerRulesPage;
use App\Livewire\Websites\CreateWebsiteForm;
use App\Livewire\Websites\WebsiteBuilderPage;
use App\Livewire\Websites\WebsiteDetailPage;
use App\Livewire\Websites\WebsiteListPage;
use App\Livewire\Workflows\EvaluationListPage as WorkflowEvaluationListPage;
use App\Livewire\Workflows\ScheduleWorkflowForm;
use App\Livewire\Workflows\WorkflowBuilderPage;
use App\Livewire\Workflows\WorkflowDetailPage;
use App\Livewire\Workflows\WorkflowListPage;
use App\Livewire\WorldModel\WorldModelPage;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

// A2A Agent Card — public discovery endpoint (RFC 8615 well-known URI, no auth required)
Route::get('/.well-known/agent.json', AgentCardController::class)
    ->name('a2a.agent-card')
    ->withoutMiddleware([SetCurrentTeam::class, BypassAuth::class, EnsureTermsAccepted::class, SetPostgresRlsContext::class])
    ->middleware('throttle:60,1');

// FleetQ discovery — public, unauthenticated. Returns auth + endpoints so MCP-compatible
// clients (OpenCode, Claude Code, Codex) can self-configure with a single URL.
Route::get('/.well-known/fleetq', WellKnownFleetQController::class)
    ->name('well-known.fleetq')
    ->withoutMiddleware([SetCurrentTeam::class, BypassAuth::class, EnsureTermsAccepted::class, SetPostgresRlsContext::class])
    ->middleware('throttle:60,1');

// Agent Chat Protocol — public manifest discovery (no auth)
Route::get('/.well-known/agents', [AgentManifestController::class, 'index'])
    ->name('agent-chat.manifest.list')
    ->withoutMiddleware([SetCurrentTeam::class, BypassAuth::class, EnsureTermsAccepted::class, SetPostgresRlsContext::class])
    ->middleware('throttle:60,1');

Route::get('/.well-known/agents/{slug}', [AgentManifestController::class, 'show'])
    ->name('agent-chat.manifest.show')
    ->withoutMiddleware([SetCurrentTeam::class, BypassAuth::class, EnsureTermsAccepted::class, SetPostgresRlsContext::class])
    ->middleware('throttle:120,1');

// Public experiment share (no auth)
Route::get('/share/{shareToken}', [PublicExperimentController::class, 'show'])->name('experiments.share');

// ── Social Login (OAuth) ──────────────────────────────────────────────────────
// Guest-only initiation + callback routes (rate limited)
Route::middleware(['guest', 'throttle:10,1'])
    ->withoutMiddleware([SetCurrentTeam::class, BypassAuth::class, EnsureTermsAccepted::class, SetPostgresRlsContext::class])
    ->group(function () {
        Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])
            ->where('provider', '[a-z0-9\-]+')
            ->name('auth.social.redirect');

        Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])
            ->where('provider', '[a-z0-9\-]+')
            ->name('auth.social.callback');

        // Apple sends callback as POST (response_mode=form_post); must bypass CSRF
        Route::post('/auth/apple/callback', [SocialAuthController::class, 'appleCallback'])
            ->name('auth.apple.callback')
            ->withoutMiddleware([VerifyCsrfToken::class]);
    });

// Email collection when provider returns no email (e.g. X/Twitter)
Route::get('/auth/social/collect-email', fn () => view('auth.social-collect-email'))
    ->withoutMiddleware([SetCurrentTeam::class, BypassAuth::class, EnsureTermsAccepted::class, SetPostgresRlsContext::class])
    ->name('auth.social.collect-email');
Route::post('/auth/social/store-email', [SocialAuthController::class, 'storeEmail'])
    ->middleware('throttle:10,1')
    ->withoutMiddleware([SetCurrentTeam::class, BypassAuth::class, EnsureTermsAccepted::class, SetPostgresRlsContext::class])
    ->name('auth.social.store-email');

// Account linking / unlinking (authenticated users)
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/auth/{provider}/link', [SocialAuthController::class, 'linkRedirect'])
        ->where('provider', '[a-z0-9\-]+')
        ->name('auth.social.link');

    Route::delete('/auth/{provider}/unlink', [SocialAuthController::class, 'unlink'])
        ->where('provider', '[a-z0-9\-]+')
        ->name('auth.social.unlink');
});
// ─────────────────────────────────────────────────────────────────────────────

// Root and /setup are self-hosted-only — the cloud edition registers its own
// versions in cloud/routes/cloud-web.php (landing page + 404 for setup).
if (config('app.deployment_mode', 'self-hosted') !== 'cloud') {
    // Root — smart redirect: setup (fresh install) → dashboard (authed) → login
    Route::get('/', function () {
        try {
            if (! User::exists()) {
                return redirect()->route('setup');
            }
        } catch (Throwable) {
            // DB unreachable — send to setup page to show diagnostics
            return redirect()->route('setup');
        }

        if (auth()->check()) {
            return redirect()->route('dashboard');
        }

        return redirect()->route('login');
    })->name('home');

    // Setup / installation check page (public — excluded from DB-dependent middleware)
    // Accepts both GET (page render) and POST (native form fallback when Livewire JS fails)
    Route::withoutMiddleware([
        SetCurrentTeam::class,
        SetPostgresRlsContext::class,
        BypassAuth::class,
    ])->match(['GET', 'POST'], '/setup', SetupPage::class)->name('setup');
}

// Documentation portal (public, no auth)
Route::get('/docs', fn () => redirect()->route('docs.show', 'introduction'))->name('docs.index');
Route::get('/docs/{page}', [DocsController::class, 'show'])->name('docs.show');

// Legal pages (public)
Route::view('/privacy', 'legal.privacy')->name('legal.privacy');
Route::view('/cookies', 'legal.cookies')->name('legal.cookies');
Route::view('/terms', 'legal.terms')->name('legal.terms');

// Use cases pages (public, SEO)
Route::view('/use-cases', 'use-cases.index')->name('use-cases.index');
Route::get('/use-cases/{slug}', UseCasesController::class)->name('use-cases.show');

// Public marketplace storefront (Blade + Alpine.js, no auth)
Route::controller(MarketplacePageController::class)->prefix('marketplace')->name('marketplace.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/category/{category}', 'category')->name('category');
    Route::get('/{listing:slug}', 'show')->name('show');
});

// In-app marketplace routes are inside the main auth group below (requires team context)

// Terms acceptance gate (auth required, but NOT verified — social users may not have verified email)
Route::middleware(['auth'])->get('/terms/accept', AcceptTermsPage::class)->name('terms.accept');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', DashboardPage::class)->name('dashboard');

    // In-app marketplace (requires team context — NOT under /app/ to avoid Reverb WebSocket proxy)
    Route::prefix('hub')->name('app.marketplace.')->group(function () {
        Route::get('/', MarketplaceBrowsePage::class)->name('index');
        Route::get('/publish', PublishForm::class)->name('publish');
        Route::get('/{listing:slug}', MarketplaceDetailPage::class)->name('show');
    });

    Route::get('/experiments', ExperimentListPage::class)->name('experiments.index');
    Route::get('/experiments/{experiment}', ExperimentDetailPage::class)->name('experiments.show');

    Route::get('/skills', SkillListPage::class)->name('skills.index');
    Route::get('/skills/create', CreateSkillForm::class)->name('skills.create');
    Route::get('/skills/{skill}', SkillDetailPage::class)->name('skills.show');

    Route::get('/frameworks', FrameworksBrowsePage::class)->name('frameworks.index');

    Route::get('/team-graph', \App\Livewire\TeamGraph\TeamGraphPage::class)->name('team-graph');

    Route::get('/agents', AgentListPage::class)->name('agents.index');
    Route::get('/agents/templates', AgentTemplateGalleryPage::class)->name('agents.templates');
    Route::get('/agents/create', CreateAgentForm::class)->name('agents.create');
    Route::get('/agents/quick', QuickAgentForm::class)->name('agents.quick');
    Route::get('/agents/{agent}/voice', VoiceSessionPage::class)->name('agents.voice');
    Route::get('/agents/{agent}', AgentDetailPage::class)->name('agents.show');

    Route::get('/external-agents', ExternalAgentListPage::class)->name('external-agents.index');
    Route::get('/external-agents/agentverse', AgentverseBrowsePage::class)->name('external-agents.agentverse');
    Route::get('/external-agents/{externalAgent}', ExternalAgentDetailPage::class)->name('external-agents.show');

    Route::get('/chatbots', ChatbotListPage::class)->name('chatbots.index');
    Route::get('/chatbots/create', CreateChatbotForm::class)->name('chatbots.create');
    Route::get('/chatbots/{chatbot}/analytics', ChatbotAnalyticsPage::class)->name('chatbots.analytics');
    Route::get('/chatbots/{chatbot}/conversations', ChatbotConversationListPage::class)->name('chatbots.conversations');
    Route::get('/chatbots/{chatbot}/knowledge', ChatbotKnowledgeBasePage::class)->name('chatbots.knowledge');
    Route::get('/chatbots/{chatbot}', ChatbotDetailPage::class)->name('chatbots.show');

    Route::get('/tools', ToolListPage::class)->name('tools.index');
    Route::get('/tools/create', CreateToolForm::class)->name('tools.create');
    Route::get('/tools/templates', ToolTemplateCatalogPage::class)->name('tools.templates');
    Route::get('/tools/marketplace', McpMarketplacePage::class)->name('tools.marketplace');
    Route::get('/tools/federation-groups', FederationGroupsPage::class)->name('tools.federation-groups');
    Route::get('/tools/search-history', ToolSearchHistoryPage::class)->name('tools.search-history');
    Route::get('/tools/{tool}', ToolDetailPage::class)->name('tools.show');

    Route::get('/credentials', CredentialListPage::class)->name('credentials.index');
    Route::get('/credentials/create', CreateCredentialForm::class)->name('credentials.create');
    Route::get('/credentials/{credential}', CredentialDetailPage::class)->name('credentials.show');

    Route::get('/integrations', IntegrationListPage::class)->name('integrations.index');
    Route::get('/integrations/oauth/{driver}', [IntegrationOAuthController::class, 'redirect'])->where('driver', '[a-z0-9_-]+')->name('integrations.oauth.redirect');
    Route::get('/integrations/oauth/{driver}/callback', [IntegrationOAuthController::class, 'callback'])->where('driver', '[a-z0-9_-]+')->name('integrations.oauth.callback');
    Route::get('/integrations/{integration}/edit', EditIntegrationForm::class)->name('integrations.edit');
    Route::get('/integrations/{integration}', IntegrationDetailPage::class)->name('integrations.show');

    Route::get('/crews', CrewListPage::class)->name('crews.index');
    Route::get('/crews/create', CreateCrewForm::class)->name('crews.create');
    Route::get('/crews/{crew}/execute', CrewExecutionPage::class)->name('crews.execute');
    Route::get('/crews/{crew}', CrewDetailPage::class)->name('crews.show');

    Route::get('/projects', ProjectListPage::class)->name('projects.index');
    Route::get('/projects/create', CreateProjectFormPage::class)->name('projects.create');
    Route::get('/projects/{project}/edit', EditProjectForm::class)->name('projects.edit');
    Route::get('/projects/{project}/kanban', ProjectKanbanPage::class)->name('projects.kanban');
    Route::get('/projects/{project}', ProjectDetailPage::class)->name('projects.show');

    Route::get('/workflows', WorkflowListPage::class)->name('workflows.index');
    Route::get('/workflows/create', WorkflowBuilderPage::class)->name('workflows.create');
    Route::get('/workflows/{workflow}/schedule', ScheduleWorkflowForm::class)->name('workflows.schedule');
    Route::get('/workflows/{workflow}/edit', WorkflowBuilderPage::class)->name('workflows.edit');
    Route::get('/workflows/{workflow}', WorkflowDetailPage::class)->name('workflows.show');

    Route::get('/artifacts/{artifact}/render/{version?}', [ArtifactPreviewController::class, 'render'])->name('artifacts.render');

    Route::get('/memory', MemoryBrowserPage::class)->name('memory.index');
    Route::get('/world-model', WorldModelPage::class)->name('world-model.index');
    Route::get('/knowledge', KnowledgeSourcesPage::class)->name('knowledge.index');
    Route::get('/knowledge-graph', KnowledgeGraphBrowserPage::class)->name('knowledge-graph.index');

    Route::get('/signals', SignalBrowserPage::class)->name('signals.index');
    Route::get('/signals/new', ManualSignalForm::class)->name('signals.create');
    Route::get('/signals/entities', EntityBrowserPage::class)->name('signals.entities');
    Route::get('/signals/connectors', SignalConnectorsPage::class)->name('signals.connectors');
    Route::get('/signals/subscriptions', ConnectorSubscriptionsPage::class)->name('signals.subscriptions');
    Route::get('/signals/bindings', ConnectorBindingsPage::class)->name('signals.bindings');

    Route::get('/bug-reports', BugReportListPage::class)->name('bug-reports.index');
    Route::get('/bug-reports/{signal}', BugReportDetailPage::class)->name('bug-reports.show');

    Route::get('/contacts', ContactsPage::class)->name('contacts.index');
    Route::get('/contacts/{contact}', ContactDetailPage::class)->name('contacts.show');

    Route::get('/metrics/models', ModelComparisonPage::class)->name('metrics.models');
    Route::get('/metrics/ai-routing', AiRoutingPage::class)->name('metrics.ai-routing');

    Route::get('/approvals', ApprovalInboxPage::class)->name('approvals.index');
    Route::get('/evaluation', EvaluationPage::class)->name('evaluation.index');
    Route::get('/evaluation/compare', EvaluationCompareRunsPage::class)->name('evaluation.compare');
    Route::get('/evaluations', WorkflowEvaluationListPage::class)->name('evaluations.index');
    Route::get('/evolution', EvolutionListPage::class)->name('evolution.index');
    Route::get('/telegram/bots', TelegramBotsPage::class)->name('telegram.bots');
    Route::get('/health', HealthPage::class)->name('health');
    Route::get('/audit', AuditLogPage::class)->name('audit');
    Route::get('/settings', GlobalSettingsPage::class)->name('settings');
    Route::get('/admin/ai', AiControlCenterPage::class)->name('admin.ai');
    Route::get('/insights', InsightsPage::class)->name('insights');
    Route::get('/plugins', PluginsPage::class)->name('plugins');
    Route::get('/team', TeamSettingsPage::class)->name('team.settings');

    Route::get('/profile', ProfilePage::class)->name('profile');

    Route::get('/notifications', NotificationInboxPage::class)->name('notifications.index');
    Route::get('/notifications/preferences', NotificationPreferencesPage::class)->name('notifications.preferences');

    Route::get('/triggers', TriggerRulesPage::class)->name('triggers.index');
    Route::get('/triggers/create', CreateTriggerRuleForm::class)->name('triggers.create');

    Route::get('/changelog', ChangelogPage::class)->name('changelog');

    // Outbound connectors
    Route::get('/outbound/email', OutboundConnectorsPage::class)->name('outbound.email');
    Route::get('/outbound/webhooks', WebhookOutboundPage::class)->name('outbound.webhooks');
    Route::get('/outbound/notifications', NotificationOutboundPage::class)->name('outbound.notifications');
    Route::get('/outbound/whatsapp', WhatsAppOutboundPage::class)->name('outbound.whatsapp');

    // Email themes
    Route::get('/email/themes', EmailThemeListPage::class)->name('email.themes.index');
    Route::get('/email/themes/{theme}', EmailThemeDetailPage::class)->name('email.themes.show');

    // Email templates
    Route::get('/email/templates', EmailTemplateListPage::class)->name('email.templates.index');
    Route::get('/email/templates/{template}/edit', EmailTemplateBuilderPage::class)->name('email.templates.edit');
    Route::get('/email/templates/{template}/preview', [EmailTemplatePreviewController::class, 'show'])->name('email.templates.preview');

    // Git Repositories
    Route::get('/git-repositories', GitRepositoryListPage::class)->name('git-repositories.index');
    Route::get('/git-repositories/create', CreateGitRepositoryForm::class)->name('git-repositories.create');
    Route::get('/git-repositories/{gitRepository}', GitRepositoryDetailPage::class)->name('git-repositories.show');

    // Websites
    Route::get('/websites', WebsiteListPage::class)->name('websites.index');
    Route::get('/websites/create', CreateWebsiteForm::class)->name('websites.create');
    Route::get('/websites/{website}', WebsiteDetailPage::class)->name('websites.show');
    Route::get('/websites/{website}/pages/{page}/edit', WebsiteBuilderPage::class)->name('websites.pages.edit');
    Route::get('/websites/{website}/pages/{page}/preview', WebsitePagePreviewController::class)->name('websites.pages.preview');
    Route::get('/websites/deployments/{deployment}/download', WebsiteDeploymentDownloadController::class)
        ->name('websites.deployment.download');

    // Audit Console (Boruna cryptographic audit trail)
    Route::get('/audit-console', AuditConsoleListPage::class)->name('audit-console.index');
    Route::get('/audit-console/{decision}', AuditConsoleDetailPage::class)->name('audit-console.show');
    Route::get('/settings/audit-console', AuditConsoleSettingsPage::class)->name('audit-console.settings');

    // WebAuthn / Passkeys (JSON endpoints — consumed by Alpine.js ceremony)
    // Routes are auto-registered by LaravelWebauthn\WebauthnServiceProvider in v5+.
    // No manual route registration needed here.
});
