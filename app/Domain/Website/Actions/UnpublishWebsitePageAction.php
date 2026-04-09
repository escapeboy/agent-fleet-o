<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Enums\WebsitePageStatus;
use App\Domain\Website\Models\WebsitePage;

class UnpublishWebsitePageAction
{
    public function execute(WebsitePage $page): WebsitePage
    {
        if ($page->status !== WebsitePageStatus::Published) {
            return $page->fresh();
        }

        $page->update([
            'status' => WebsitePageStatus::Draft,
            'published_at' => null,
        ]);

        return $page->fresh();
    }
}
