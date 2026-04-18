<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Enums\WebsitePageStatus;
use App\Domain\Website\Models\WebsitePage;
use App\Domain\Website\Services\HtmlSanitizer;

class UpdateWebsitePageAction
{
    public function __construct(
        private readonly EnhanceWebsiteNavigationAction $enhance,
    ) {}

    public function execute(WebsitePage $page, array $data): WebsitePage
    {
        $fields = ['slug', 'title', 'page_type', 'status', 'grapes_json', 'exported_html', 'exported_css', 'meta', 'sort_order'];

        if (isset($data['exported_html']) && $data['exported_html'] !== null) {
            $data['exported_html'] = HtmlSanitizer::purify($data['exported_html']);
        }

        $updates = [];
        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $updates[$field] = $data[$field];
            }
        }

        $slugChanged = isset($updates['slug']) && $updates['slug'] !== $page->slug;
        $wasPublished = $page->status === WebsitePageStatus::Published;

        if ($updates) {
            $page->update($updates);
        }

        // If the slug of a published page changed, every other page's nav
        // needs to be rewritten so the links still resolve.
        if ($slugChanged && $wasPublished && $website = $page->website) {
            $this->enhance->execute($website, publishedOnly: true);
        }

        return $page->fresh();
    }
}
