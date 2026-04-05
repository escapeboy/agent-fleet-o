<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Models\WebsitePage;

class UnpublishWebsitePageAction
{
    public function execute(WebsitePage $page): WebsitePage
    {
        $page->update(['status' => 'draft']);

        return $page->fresh();
    }
}
