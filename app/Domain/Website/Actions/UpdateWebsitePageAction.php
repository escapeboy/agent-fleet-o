<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Models\WebsitePage;

class UpdateWebsitePageAction
{
    public function execute(WebsitePage $page, array $data): WebsitePage
    {
        $fields = ['slug', 'title', 'page_type', 'status', 'grapes_json', 'exported_html', 'exported_css', 'meta', 'sort_order'];

        $updates = [];
        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $updates[$field] = $data[$field];
            }
        }

        if ($updates) {
            $page->update($updates);
        }

        return $page->fresh();
    }
}
