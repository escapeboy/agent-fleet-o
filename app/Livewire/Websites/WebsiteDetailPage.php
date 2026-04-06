<?php

namespace App\Livewire\Websites;

use App\Domain\Crew\Models\Crew;
use App\Domain\Project\Models\Project;
use App\Domain\Website\Actions\AssignWebsiteCrewAction;
use App\Domain\Website\Actions\CreateWebsitePageAction;
use App\Domain\Website\Actions\DeleteWebsiteAction;
use App\Domain\Website\Actions\DeleteWebsitePageAction;
use App\Domain\Website\Actions\ExecuteWebsiteCommandAction;
use App\Domain\Website\Actions\PublishWebsitePageAction;
use App\Domain\Website\Actions\UnpublishWebsitePageAction;
use App\Domain\Website\Actions\UpdateWebsiteAction;
use App\Domain\Website\Actions\UploadWebsiteAssetAction;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class WebsiteDetailPage extends Component
{
    use WithFileUploads;

    #[Locked]
    public Website $website;

    // Edit website form
    public bool $editingWebsite = false;

    public string $editName = '';

    public string $editSlug = '';

    public string $editCustomDomain = '';

    // New page form
    public bool $addingPage = false;

    public string $newPageTitle = '';

    public string $newPageSlug = '';

    public string $newPageType = 'page';

    public string $newPageBrief = '';

    // Asset upload
    public mixed $newAsset = null;

    // Command panel
    public string $command = '';

    public ?string $commandPageId = null;

    public bool $commandLoading = false;

    public ?string $commandCrewExecutionId = null;

    public ?string $commandError = null;

    // Crew assignment
    public string $assigningCrewId = '';

    // Linked projects section
    public bool $linkingProject = false;

    public string $linkProjectId = '';

    public function mount(Website $website): void
    {
        $this->website = $website->load(['pages', 'assets', 'managingCrew', 'projects']);
    }

    public function startEditWebsite(): void
    {
        $this->editName = $this->website->name;
        $this->editSlug = $this->website->slug;
        $this->editCustomDomain = $this->website->custom_domain ?? '';
        $this->editingWebsite = true;
    }

    public function saveWebsite(UpdateWebsiteAction $action): void
    {
        $this->validate([
            'editName' => ['required', 'string', 'max:255'],
            'editSlug' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]*$/'],
            'editCustomDomain' => ['nullable', 'string', 'max:255'],
        ]);

        $this->website = $action->execute(
            website: $this->website,
            data: [
                'name' => $this->editName,
                'slug' => $this->editSlug ?: null,
                'custom_domain' => $this->editCustomDomain ?: null,
            ],
        );

        $this->editingWebsite = false;
    }

    public function publish(UpdateWebsiteAction $action): void
    {
        $this->website = $action->execute(website: $this->website, data: ['status' => WebsiteStatus::Published->value]);
    }

    public function unpublish(UpdateWebsiteAction $action): void
    {
        $this->website = $action->execute(website: $this->website, data: ['status' => WebsiteStatus::Draft->value]);
    }

    public function deleteWebsite(DeleteWebsiteAction $action): void
    {
        $action->execute($this->website);
        $this->redirectRoute('websites.index');
    }

    public function updatedNewPageTitle(): void
    {
        if (! $this->newPageSlug) {
            $this->newPageSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $this->newPageTitle) ?? '');
        }
    }

    public function addPage(CreateWebsitePageAction $action, ExecuteWebsiteCommandAction $commandAction): void
    {
        $this->validate([
            'newPageTitle' => ['required', 'string', 'max:255'],
            'newPageSlug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/'],
            'newPageType' => ['required', 'string', 'in:page,post,product,landing'],
            'newPageBrief' => ['nullable', 'string', 'max:2000'],
        ]);

        $page = $action->execute(
            website: $this->website,
            data: [
                'slug' => $this->newPageSlug,
                'title' => $this->newPageTitle,
                'page_type' => $this->newPageType,
            ],
        );

        $brief = trim($this->newPageBrief);

        if ($brief !== '' && $this->website->managing_crew_id) {
            $command = "Generate the full HTML content for the '{$page->title}' page (/{$page->slug}, type: {$this->newPageType}).\n\nBrief:\n{$brief}";
            $commandAction->execute($this->website, $command, $page->id);
        }

        $this->reset('newPageTitle', 'newPageSlug', 'newPageType', 'newPageBrief', 'addingPage');
        $this->website->load('pages');
    }

    public function publishPage(string $pageId, PublishWebsitePageAction $action): void
    {
        $page = $this->website->pages->firstWhere('id', $pageId);
        if ($page) {
            $action->execute($page);
            $this->website->load('pages');
        }
    }

    public function unpublishPage(string $pageId, UnpublishWebsitePageAction $action): void
    {
        $page = $this->website->pages->firstWhere('id', $pageId);
        if ($page) {
            $action->execute($page);
            $this->website->load('pages');
        }
    }

    public function deletePage(string $pageId, DeleteWebsitePageAction $action): void
    {
        $page = $this->website->pages->firstWhere('id', $pageId);
        if ($page) {
            $action->execute($page);
            $this->website->load('pages');
        }
    }

    public function uploadAsset(UploadWebsiteAssetAction $action): void
    {
        $this->validate([
            'newAsset' => ['required', 'image', 'max:5120'],
        ]);

        $action->execute($this->website, $this->newAsset);
        $this->newAsset = null;
        $this->website->load('assets');
    }

    public function deleteAsset(string $assetId): void
    {
        $asset = $this->website->assets->firstWhere('id', $assetId);
        if ($asset) {
            Storage::disk($asset->disk)->delete($asset->path);
            $asset->delete();
            $this->website->load('assets');
        }
    }

    public function executeCommand(ExecuteWebsiteCommandAction $action): void
    {
        $this->commandError = null;

        $this->validate(['command' => ['required', 'string']]);

        $this->commandLoading = true;

        try {
            $execution = $action->execute($this->website, $this->command, $this->commandPageId);
            $this->commandCrewExecutionId = $execution->id;
            $this->command = '';
            $this->commandPageId = null;
            $this->dispatch('notify', ['type' => 'success', 'message' => 'Command sent to crew']);
        } catch (InvalidArgumentException $e) {
            $this->commandError = $e->getMessage();
        } finally {
            $this->commandLoading = false;
        }
    }

    public function setCommandPage(string $pageId): void
    {
        $this->commandPageId = $pageId;
    }

    public function clearCommandPage(): void
    {
        $this->commandPageId = null;
    }

    public function assignCrew(AssignWebsiteCrewAction $action): void
    {
        try {
            $action->execute($this->website, $this->assigningCrewId ?: null);
            $this->website->refresh();
            $this->website->load('managingCrew');
            $this->dispatch('notify', ['type' => 'success', 'message' => 'Managing crew updated']);
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', ['type' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function linkProject(): void
    {
        $this->validate(['linkProjectId' => ['required', 'string']]);

        Project::find($this->linkProjectId)?->update(['website_id' => $this->website->id]);
        $this->website->load('projects');

        $this->availableProjects = Project::where('team_id', $this->website->team_id)
            ->whereNull('website_id')
            ->get();

        $this->linkingProject = false;
        $this->linkProjectId = '';
    }

    public function unlinkProject(string $projectId): void
    {
        Project::find($projectId)?->update(['website_id' => null]);
        $this->website->load('projects');

        $this->availableProjects = Project::where('team_id', $this->website->team_id)
            ->whereNull('website_id')
            ->get();
    }

    public function render()
    {
        if ($this->website->isGenerating()) {
            $this->website = $this->website->fresh(['pages']) ?? $this->website;
        }

        $availableCrews = Crew::where('team_id', $this->website->team_id)
            ->where('status', 'active')
            ->get();

        $availableProjects = Project::where('team_id', $this->website->team_id)
            ->whereNull('website_id')
            ->get();

        return view('livewire.websites.website-detail-page', compact('availableCrews', 'availableProjects'))
            ->layout('layouts.app', ['header' => $this->website->name]);
    }
}
