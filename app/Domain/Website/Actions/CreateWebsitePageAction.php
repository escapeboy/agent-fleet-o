<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsitePage;

class CreateWebsitePageAction
{
    public function execute(Website $website, array $data): WebsitePage
    {
        $maxOrder = $website->pages()->max('sort_order') ?? -1;

        return WebsitePage::create([
            'website_id' => $website->id,
            'team_id' => $website->team_id,
            'slug' => $data['slug'],
            'title' => $data['title'],
            'page_type' => $data['page_type'] ?? 'page',
            'status' => 'draft',
            'grapes_json' => $data['grapes_json'] ?? null,
            'exported_html' => $data['exported_html'] ?? null,
            'exported_css' => $data['exported_css'] ?? null,
            'meta' => $data['meta'] ?? [],
            'sort_order' => $maxOrder + 1,
        ]);
    }
}
