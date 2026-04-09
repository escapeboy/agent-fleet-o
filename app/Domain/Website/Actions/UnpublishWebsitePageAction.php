<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Enums\WebsitePageStatus;
use App\Domain\Website\Models\WebsitePage;

class UnpublishWebsitePageAction
{
    public function __construct(
        private readonly EnhanceWebsiteNavigationAction $enhance,
    ) {}

    public function execute(WebsitePage $page): WebsitePage
    {
        if ($page->status !== WebsitePageStatus::Published) {
            return $page->fresh();
        }

        $page->update([
            'status' => WebsitePageStatus::Draft,
            'published_at' => null,
        ]);

        // Rebuild nav on every other published page so the unpublished page is
        // removed from their navigation.
        if ($website = $page->website) {
            $this->enhance->execute($website, publishedOnly: true);
        }

        return $page->fresh();
    }
}
