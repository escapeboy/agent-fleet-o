<?php

namespace App\Livewire\Websites;

use App\Domain\Website\Actions\UpdateWebsitePageAction;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsitePage;
use App\Domain\Website\Services\GrapesJsExporter;
use App\Domain\Website\Services\WebsiteBlockRegistry;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

class WebsiteBuilderPage extends Component
{
    #[Locked]
    public Website $website;

    #[Locked]
    public WebsitePage $page;

    /** @var array<string, mixed> Serialised GrapesJS editor state (synced via Alpine.js) */
    public array $grapesJson = [];

    public string $exportedHtml = '';

    public string $exportedCss = '';

    public function mount(Website $website, WebsitePage $page): void
    {
        $this->website = $website;
        $this->page = $page;
        $this->grapesJson = $page->grapes_json ?? [];
        $this->exportedHtml = app(GrapesJsExporter::class)->stripLlmPreamble($page->exported_html ?? '');
        $this->exportedCss = $page->exported_css ?? '';
    }

    /**
     * Called by Alpine.js when the editor content changes and the user clicks Save.
     * Renderless: Livewire must NOT re-render after this action — doing so would
     * re-initialize GrapesJS via x-init, doubling blocks and freezing the page.
     *
     * @param  array<string, mixed>  $grapesJson  Full GrapesJS project data
     * @param  string  $html  Cleaned HTML from GrapesJS
     * @param  string  $css  Cleaned CSS from GrapesJS
     */
    #[Renderless]
    public function save(array $grapesJson, string $html, string $css, UpdateWebsitePageAction $action): void
    {
        $action->execute(
            page: $this->page,
            data: [
                'grapes_json' => $grapesJson,
                'exported_html' => $html,
                'exported_css' => $css,
            ],
        );
    }

    public function render()
    {
        $registry = app(WebsiteBlockRegistry::class);

        return view('livewire.websites.website-builder-page', [
            'blocks' => $registry->blocks(),
            'editorScripts' => $registry->scripts(),
            'editorStyles' => $registry->styles(),
        ])->layout('layouts.app', [
            'header' => "Builder — {$this->page->title}",
            // Suppress @mcp-b/global on the builder page — its DOM-mutation observer
            // fires on every GrapesJS panel update and freezes the renderer.
            'suppressWebmcp' => true,
        ]);
    }
}
