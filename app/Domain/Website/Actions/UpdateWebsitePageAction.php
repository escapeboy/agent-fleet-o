<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Models\WebsitePage;
use App\Domain\Website\Services\HtmlSanitizer;

class UpdateWebsitePageAction
{
    public function execute(WebsitePage $page, array $data): WebsitePage
    {
        $fields = ['slug', 'title', 'page_type', 'status', 'grapes_json', 'exported_html', 'exported_css', 'meta', 'sort_order'];

        if (isset($data['exported_html']) && $data['exported_html'] !== null) {
            $data['exported_html'] = HtmlSanitizer::purify($data['exported_html']);
        }

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
