<?php

use App\Http\Controllers\ArtifactPreviewController;
use App\Livewire\Agents\AgentDetailPage;
use App\Livewire\Agents\AgentListPage;
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
use App\Livewire\Marketplace\MarketplaceBrowsePage;
use App\Livewire\Marketplace\MarketplaceDetailPage;
use App\Livewire\Marketplace\PublishForm;
use App\Livewire\Memory\MemoryBrowserPage;
use App\Livewire\Projects\CreateProjectForm as CreateProjectFormPage;
use App\Livewire\Projects\EditProjectForm;
use App\Livewire\Projects\ProjectDetailPage;
use App\Livewire\Projects\ProjectListPage;
use App\Livewire\Settings\GlobalSettingsPage;
use App\Livewire\Skills\CreateSkillForm;
use App\Livewire\Skills\SkillDetailPage;
use App\Livewire\Skills\SkillListPage;
use App\Livewire\Teams\TeamSettingsPage;
use App\Livewire\Tools\CreateToolForm;
use App\Livewire\Tools\ToolDetailPage;
use App\Livewire\Tools\ToolListPage;
use App\Livewire\Workflows\ScheduleWorkflowForm;
use App\Livewire\Workflows\WorkflowBuilderPage;
use App\Livewire\Workflows\WorkflowDetailPage;
use App\Livewire\Workflows\WorkflowListPage;
use Illuminate\Support\Facades\Route;

// Root — redirect to dashboard or login
Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('home');

// Marketplace: browse is public, publish requires auth, detail (slug) must be last
Route::get('/marketplace', MarketplaceBrowsePage::class)->name('marketplace.index');
Route::get('/marketplace/publish', PublishForm::class)->middleware(['auth', 'verified'])->name('marketplace.publish');
Route::get('/marketplace/{listing:slug}', MarketplaceDetailPage::class)->name('marketplace.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', DashboardPage::class)->name('dashboard');

    Route::get('/experiments', ExperimentListPage::class)->name('experiments.index');
    Route::get('/experiments/{experiment}', ExperimentDetailPage::class)->name('experiments.show');

    Route::get('/skills', SkillListPage::class)->name('skills.index');
    Route::get('/skills/create', CreateSkillForm::class)->name('skills.create');
    Route::get('/skills/{skill}', SkillDetailPage::class)->name('skills.show');

    Route::get('/agents', AgentListPage::class)->name('agents.index');
    Route::get('/agents/create', CreateAgentForm::class)->name('agents.create');
    Route::get('/agents/{agent}', AgentDetailPage::class)->name('agents.show');

    Route::get('/tools', ToolListPage::class)->name('tools.index');
    Route::get('/tools/create', CreateToolForm::class)->name('tools.create');
    Route::get('/tools/{tool}', ToolDetailPage::class)->name('tools.show');

    Route::get('/credentials', CredentialListPage::class)->name('credentials.index');
    Route::get('/credentials/create', CreateCredentialForm::class)->name('credentials.create');
    Route::get('/credentials/{credential}', CredentialDetailPage::class)->name('credentials.show');

    Route::get('/crews', CrewListPage::class)->name('crews.index');
    Route::get('/crews/create', CreateCrewForm::class)->name('crews.create');
    Route::get('/crews/{crew}/execute', CrewExecutionPage::class)->name('crews.execute');
    Route::get('/crews/{crew}', CrewDetailPage::class)->name('crews.show');

    Route::get('/projects', ProjectListPage::class)->name('projects.index');
    Route::get('/projects/create', CreateProjectFormPage::class)->name('projects.create');
    Route::get('/projects/{project}/edit', EditProjectForm::class)->name('projects.edit');
    Route::get('/projects/{project}', ProjectDetailPage::class)->name('projects.show');

    Route::get('/workflows', WorkflowListPage::class)->name('workflows.index');
    Route::get('/workflows/create', WorkflowBuilderPage::class)->name('workflows.create');
    Route::get('/workflows/{workflow}/schedule', ScheduleWorkflowForm::class)->name('workflows.schedule');
    Route::get('/workflows/{workflow}/edit', WorkflowBuilderPage::class)->name('workflows.edit');
    Route::get('/workflows/{workflow}', WorkflowDetailPage::class)->name('workflows.show');

    Route::get('/artifacts/{artifact}/render/{version?}', [ArtifactPreviewController::class, 'render'])->name('artifacts.render');

    Route::get('/memory', MemoryBrowserPage::class)->name('memory.index');

    Route::get('/approvals', ApprovalInboxPage::class)->name('approvals.index');
    Route::get('/health', HealthPage::class)->name('health');
    Route::get('/audit', AuditLogPage::class)->name('audit');
    Route::get('/settings', GlobalSettingsPage::class)->name('settings');
    Route::get('/team', TeamSettingsPage::class)->name('team.settings');
});
