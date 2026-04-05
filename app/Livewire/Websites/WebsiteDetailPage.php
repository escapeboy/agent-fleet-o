<?php

namespace App\Livewire\Websites;

use App\Domain\Website\Actions\CreateWebsitePageAction;
use App\Domain\Website\Actions\DeleteWebsiteAction;
use App\Domain\Website\Actions\DeleteWebsitePageAction;
use App\Domain\Website\Actions\PublishWebsitePageAction;
use App\Domain\Website\Actions\UnpublishWebsitePageAction;
use App\Domain\Website\Actions\UpdateWebsiteAction;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use Livewire\Attributes\Locked;
use Livewire\Component;

class WebsiteDetailPage extends Component
{
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

    public function mount(Website $website): void
    {
        $this->website = $website->load('pages');
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

    public function addPage(CreateWebsitePageAction $action): void
    {
        $this->validate([
            'newPageTitle' => ['required', 'string', 'max:255'],
            'newPageSlug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/'],
            'newPageType' => ['required', 'string', 'in:page,post,product,landing'],
        ]);

        $page = $action->execute(
            website: $this->website,
            data: [
                'slug' => $this->newPageSlug,
                'title' => $this->newPageTitle,
                'page_type' => $this->newPageType,
            ],
        );

        $this->reset('newPageTitle', 'newPageSlug', 'newPageType', 'addingPage');
        $this->website->load('pages');

        $this->redirectRoute('websites.builder', ['website' => $this->website, 'page' => $page]);
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

    public function render()
    {
        if ($this->website->isGenerating()) {
            $this->website = $this->website->fresh(['pages']) ?? $this->website;
        }

        return view('livewire.websites.website-detail-page')
            ->layout('layouts.app', ['header' => $this->website->name]);
    }
}
