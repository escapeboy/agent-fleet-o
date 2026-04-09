<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Enums\WebsitePageStatus;
use App\Domain\Website\Models\WebsitePage;

class PublishWebsitePageAction
{
    public function __construct(
        private readonly EnhanceWebsiteNavigationAction $enhance,
    ) {}

    public function execute(WebsitePage $page): WebsitePage
    {
        if ($page->exported_html === null) {
            throw new \RuntimeException('Page has no exported HTML to publish');
        }

        $page->update([
            'status' => WebsitePageStatus::Published,
            'published_at' => now(),
        ]);

        // Refresh nav on every sibling page so the newly-published page shows up
        // in their navigation. Only published pages appear in the public nav.
        if ($website = $page->website) {
            $this->enhance->execute($website, publishedOnly: true);
        }

        return $page->fresh();
    }
}
