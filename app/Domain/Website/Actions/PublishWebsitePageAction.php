<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Models\WebsitePage;

class PublishWebsitePageAction
{
    public function execute(WebsitePage $page): WebsitePage
    {
        $page->update([
            'status' => 'published',
            'published_at' => $page->published_at ?? now(),
        ]);

        return $page->fresh();
    }
}
