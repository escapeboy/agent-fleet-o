<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Models\WebsitePage;

class DeleteWebsitePageAction
{
    public function execute(WebsitePage $page): void
    {
        $page->delete();
    }
}
