<?php

namespace App\Domain\Website\Observers;

use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsitePage;
use Illuminate\Support\Facades\DB;

/**
 * Bumps the parent website's content_version whenever a page is created,
 * updated, or deleted. Widget caches use this version as part of their
 * cache key, so a bump effectively invalidates every cached widget for
 * that website without touching Redis directly.
 */
class WebsitePageObserver
{
    public function saved(WebsitePage $page): void
    {
        $this->bumpVersion($page);
    }

    public function deleted(WebsitePage $page): void
    {
        $this->bumpVersion($page);
    }

    private function bumpVersion(WebsitePage $page): void
    {
        if (! $page->website_id) {
            return;
        }

        Website::query()
            ->whereKey($page->website_id)
            ->update(['content_version' => DB::raw('content_version + 1')]);
    }
}
