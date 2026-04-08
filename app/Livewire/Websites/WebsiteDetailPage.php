<?php

namespace App\Livewire\Websites;

use App\Domain\Website\Actions\CreateWebsitePageAction;
use App\Domain\Website\Actions\DeleteWebsiteAction;
use App\Domain\Website\Actions\DeleteWebsitePageAction;
use App\Domain\Website\Actions\PublishWebsitePageAction;
use App\Domain\Website\Actions\UpdateWebsiteAction;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsitePage;
use Illuminate\Support\Str;
use Livewire\Component;

class WebsiteDetailPage extends Component
{
    public Website $website;

    public bool $showAddPage = false;

    public string $pageTitle = '';

    public string $pageSlug = '';

    public string $pageType = 'page';

    public function mount(Website $website): void
    {
        $this->website = $website;
    }

    public function updatedPageTitle(): void
    {
        $this->pageSlug = Str::slug($this->pageTitle);
    }

    public function addPage(): void
    {
        $this->validate([
            'pageTitle' => 'required|max:255',
            'pageSlug' => 'required|max:255',
        ]);

        app(CreateWebsitePageAction::class)->execute($this->website, [
            'title' => $this->pageTitle,
            'slug' => $this->pageSlug,
            'page_type' => $this->pageType,
        ]);

        $this->pageTitle = '';
        $this->pageSlug = '';
        $this->pageType = 'page';
        $this->showAddPage = false;

        session()->flash('success', 'Page added.');
        $this->website->refresh();
    }

    public function deletePage(string $pageId): void
    {
        // Scope to this website — prevents cross-website page deletion within same team
        $page = $this->website->pages()->findOrFail($pageId);
        app(DeleteWebsitePageAction::class)->execute($page);

        session()->flash('success', 'Page deleted.');
        $this->website->refresh();
    }

    public function publishPage(string $pageId): void
    {
        // Scope to this website — prevents cross-website page publishing within same team
        $page = $this->website->pages()->findOrFail($pageId);
        app(PublishWebsitePageAction::class)->execute($page);

        session()->flash('success', 'Page published.');
        $this->website->refresh();
    }

    public function publishWebsite(): void
    {
        // Only publish draft pages that have content — skip empty drafts
        $this->website->pages()
            ->where('status', 'draft')
            ->whereNotNull('exported_html')
            ->get()
            ->each(function (WebsitePage $page): void {
                app(PublishWebsitePageAction::class)->execute($page);
            });

        app(UpdateWebsiteAction::class)->execute($this->website, ['status' => WebsiteStatus::Published]);

        session()->flash('success', 'Website published.');
        $this->website->refresh();
    }

    public function deleteWebsite(): void
    {
        app(DeleteWebsiteAction::class)->execute($this->website);

        session()->flash('success', 'Website deleted.');
        $this->redirectRoute('websites.index');
    }

    public function render()
    {
        return view('livewire.websites.website-detail-page', [
            'pages' => $this->website->pages()->orderBy('sort_order')->get(),
        ])->layout('layouts.app', ['header' => $this->website->name]);
    }
}
