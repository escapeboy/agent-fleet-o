<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Models\WebsitePage;

class DeleteWebsitePageAction
{
    public function __construct(
        private readonly EnhanceWebsiteNavigationAction $enhance,
    ) {}

    public function execute(WebsitePage $page): void
    {
        $website = $page->website;

        $page->delete();

        // Refresh nav on the remaining published pages so the deleted slug
        // disappears from their navigation.
        if ($website) {
            $this->enhance->execute($website, publishedOnly: true);
        }
    }
}
