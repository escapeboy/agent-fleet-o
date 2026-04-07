<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Enums\WebsitePageStatus;
use App\Domain\Website\Models\WebsitePage;

class PublishWebsitePageAction
{
    public function execute(WebsitePage $page): WebsitePage
    {
        if ($page->exported_html === null) {
            throw new \RuntimeException('Page has no exported HTML to publish');
        }

        $page->update([
            'status' => WebsitePageStatus::Published,
            'published_at' => now(),
        ]);

        return $page->fresh();
    }
}
