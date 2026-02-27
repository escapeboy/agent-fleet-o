<?php

use App\Http\Controllers\ArtifactPreviewController;
use App\Http\Controllers\IntegrationOAuthController;
use App\Http\Controllers\MarketplacePageController;
use App\Http\Controllers\PublicExperimentController;
use App\Http\Middleware\SetCurrentTeam;
use App\Http\Middleware\SetPostgresRlsContext;
use App\Livewire\Agents\AgentDetailPage;
use App\Livewire\Agents\AgentListPage;
use App\Livewire\Agents\AgentTemplateGalleryPage;
use App\Livewire\Agents\CreateAgentForm;
use App\Livewire\Approvals\ApprovalInboxPage;
use App\Livewire\Audit\AuditLogPage;
use App\Livewire\Credentials\CreateCredentialForm;
use App\Livewire\Credentials\CredentialDetailPage;
use App\Livewire\Credentials\CredentialListPage;
use App\Livewire\Crews\CreateCrewForm;
use App\Livewire\Crews\CrewDetailPage;
use App\Livewire\Crews\CrewExecutionPage;
use App\Livewire\Crews\CrewListPage;
use App\Livewire\Dashboard\DashboardPage;
use App\Livewire\Experiments\ExperimentDetailPage;
use App\Livewire\Experiments\ExperimentListPage;
use App\Livewire\Health\HealthPage;
use App\Livewire\Integrations\IntegrationDetailPage;
use App\Livewire\Integrations\IntegrationListPage;
use App\Livewire\Marketplace\MarketplaceBrowsePage;
use App\Livewire\Marketplace\MarketplaceDetailPage;
use App\Livewire\Marketplace\PublishForm;
use App\Livewire\Memory\MemoryBrowserPage;
use App\Livewire\Metrics\ModelComparisonPage;
use App\Livewire\Projects\CreateProjectForm as CreateProjectFormPage;
use App\Livewire\Projects\EditProjectForm;
use App\Livewire\Projects\ProjectDetailPage;
use App\Livewire\Projects\ProjectKanbanPage;
use App\Livewire\Projects\ProjectListPage;
use App\Livewire\Settings\GlobalSettingsPage;
use App\Livewire\Setup\SetupPage;
use App\Livewire\Shared\NotificationInboxPage;
use App\Livewire\Shared\NotificationPreferencesPage;
use App\Livewire\Signals\ConnectorBindingsPage;
use App\Livewire\Signals\ContactDetailPage;
use App\Livewire\Signals\ContactsPage;
use App\Livewire\Signals\EntityBrowserPage;
use App\Livewire\Signals\SignalConnectorsPage;
use App\Livewire\Skills\CreateSkillForm;
use App\Livewire\Skills\SkillDetailPage;
use App\Livewire\Skills\SkillListPage;
use App\Livewire\Teams\TeamSettingsPage;
use App\Livewire\Tools\CreateToolForm;
use App\Livewire\Tools\ToolDetailPage;
use App\Livewire\Tools\ToolListPage;
use App\Livewire\Triggers\CreateTriggerRuleForm;
use App\Livewire\Triggers\TriggerRulesPage;
use App\Livewire\Workflows\ScheduleWorkflowForm;
use App\Livewire\Workflows\WorkflowBuilderPage;
use App\Livewire\Workflows\WorkflowDetailPage;
use App\Livewire\Workflows\WorkflowListPage;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// Public experiment share (no auth)
Route::get('/share/{shareToken}', [PublicExperimentController::class, 'show'])->name('experiments.share');

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
Route::withoutMiddleware([
    SetCurrentTeam::class,
    SetPostgresRlsContext::class,
])->get('/setup', SetupPage::class)->name('setup');

// Legal pages (public)
Route::get('/privacy', fn () => view('legal.privacy'))->name('legal.privacy');
Route::get('/cookies', fn () => view('legal.cookies'))->name('legal.cookies');
Route::get('/terms', fn () => view('legal.terms'))->name('legal.terms');

// Public marketplace storefront (Blade + Alpine.js, no auth)
Route::controller(MarketplacePageController::class)->prefix('marketplace')->name('marketplace.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/category/{category}', 'category')->name('category');
    Route::get('/{listing:slug}', 'show')->name('show');
});

// In-app marketplace (Livewire, auth required)
Route::middleware(['auth', 'verified'])->prefix('app/marketplace')->name('app.marketplace.')->group(function () {
    Route::get('/', MarketplaceBrowsePage::class)->name('index');
    Route::get('/publish', PublishForm::class)->name('publish');
    Route::get('/{listing:slug}', MarketplaceDetailPage::class)->name('show');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', DashboardPage::class)->name('dashboard');

    Route::get('/experiments', ExperimentListPage::class)->name('experiments.index');
    Route::get('/experiments/{experiment}', ExperimentDetailPage::class)->name('experiments.show');

    Route::get('/skills', SkillListPage::class)->name('skills.index');
    Route::get('/skills/create', CreateSkillForm::class)->name('skills.create');
    Route::get('/skills/{skill}', SkillDetailPage::class)->name('skills.show');

    Route::get('/agents', AgentListPage::class)->name('agents.index');
    Route::get('/agents/templates', AgentTemplateGalleryPage::class)->name('agents.templates');
    Route::get('/agents/create', CreateAgentForm::class)->name('agents.create');
    Route::get('/agents/{agent}', AgentDetailPage::class)->name('agents.show');

    Route::get('/tools', ToolListPage::class)->name('tools.index');
    Route::get('/tools/create', CreateToolForm::class)->name('tools.create');
    Route::get('/tools/{tool}', ToolDetailPage::class)->name('tools.show');

    Route::get('/credentials', CredentialListPage::class)->name('credentials.index');
    Route::get('/credentials/create', CreateCredentialForm::class)->name('credentials.create');
    Route::get('/credentials/{credential}', CredentialDetailPage::class)->name('credentials.show');

    Route::get('/integrations', IntegrationListPage::class)->name('integrations.index');
    Route::get('/integrations/oauth/{driver}', [IntegrationOAuthController::class, 'redirect'])->name('integrations.oauth.redirect');
    Route::get('/integrations/oauth/{driver}/callback', [IntegrationOAuthController::class, 'callback'])->name('integrations.oauth.callback');
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

    Route::get('/signals/entities', EntityBrowserPage::class)->name('signals.entities');
    Route::get('/signals/connectors', SignalConnectorsPage::class)->name('signals.connectors');
    Route::get('/signals/bindings', ConnectorBindingsPage::class)->name('signals.bindings');

    Route::get('/contacts', ContactsPage::class)->name('contacts.index');
    Route::get('/contacts/{contact}', ContactDetailPage::class)->name('contacts.show');

    Route::get('/metrics/models', ModelComparisonPage::class)->name('metrics.models');

    Route::get('/approvals', ApprovalInboxPage::class)->name('approvals.index');
    Route::get('/health', HealthPage::class)->name('health');
    Route::get('/audit', AuditLogPage::class)->name('audit');
    Route::get('/settings', GlobalSettingsPage::class)->name('settings');
    Route::get('/team', TeamSettingsPage::class)->name('team.settings');

    Route::get('/notifications', NotificationInboxPage::class)->name('notifications.index');
    Route::get('/notifications/preferences', NotificationPreferencesPage::class)->name('notifications.preferences');

    Route::get('/triggers', TriggerRulesPage::class)->name('triggers.index');
    Route::get('/triggers/create', CreateTriggerRuleForm::class)->name('triggers.create');
});
