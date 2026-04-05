<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Models\WebsitePage;

class UpdateWebsitePageAction
{
    public function execute(WebsitePage $page, array $data): WebsitePage
    {
        $fillable = array_intersect_key($data, array_flip([
            'title', 'slug', 'page_type', 'grapes_json',
            'exported_html', 'exported_css', 'meta', 'sort_order',
        ]));

        $page->update($fillable);

        return $page->fresh();
    }
}
