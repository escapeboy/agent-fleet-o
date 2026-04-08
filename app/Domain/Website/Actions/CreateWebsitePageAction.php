<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Enums\WebsitePageStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsitePage;

class CreateWebsitePageAction
{
    public function execute(Website $website, array $data): WebsitePage
    {
        $sortOrder = $website->pages()->count() + 1;

        return WebsitePage::create([
            'website_id' => $website->id,
            'team_id' => $website->team_id,
            'slug' => $data['slug'],
            'title' => $data['title'],
            'page_type' => $data['page_type'],
            'status' => WebsitePageStatus::Draft,
            'sort_order' => $data['sort_order'] ?? $sortOrder,
        ]);
    }
}
