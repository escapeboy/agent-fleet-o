<?php

namespace App\Livewire\Websites;

use App\Domain\Website\Actions\PublishWebsitePageAction;
use App\Domain\Website\Actions\UpdateWebsitePageAction;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsitePage;
use Livewire\Component;

class WebsiteBuilderPage extends Component
{
    public Website $website;

    public WebsitePage $page;

    public string $title = '';

    public string $slug = '';

    public string $pageType = 'page';

    public function mount(Website $website, WebsitePage $page): void
    {
        $this->website = $website;
        $this->page = $page;
        $this->title = $page->title;
        $this->slug = $page->slug;
        $this->pageType = $page->page_type->value;
    }

    public function saveContent(array $grapesJson, string $html, string $css): void
    {
        app(UpdateWebsitePageAction::class)->execute($this->page, [
            'grapes_json' => $grapesJson,
            'exported_html' => $html,
            'exported_css' => $css,
        ]);

        $this->dispatch('saved');
    }

    public function saveSettings(): void
    {
        $this->validate([
            'title' => 'required|max:255',
            'slug' => 'required|max:255',
        ]);

        app(UpdateWebsitePageAction::class)->execute($this->page, [
            'title' => $this->title,
            'slug' => $this->slug,
            'page_type' => $this->pageType,
        ]);

        session()->flash('message', 'Settings saved.');
    }

    public function publishPage(): void
    {
        app(PublishWebsitePageAction::class)->execute($this->page);

        session()->flash('message', 'Page published.');
    }

    public function render()
    {
        return view('livewire.websites.website-builder-page')
            ->layout('layouts.app', ['header' => $this->page->title]);
    }
}
