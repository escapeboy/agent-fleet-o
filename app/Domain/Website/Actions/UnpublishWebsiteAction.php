<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Enums\WebsitePageStatus;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use Illuminate\Support\Facades\DB;

class UnpublishWebsiteAction
{
    public function __construct(
        private readonly UnpublishWebsitePageAction $unpublishPage,
    ) {}

    public function execute(Website $website): Website
    {
        DB::transaction(function () use ($website): void {
            foreach ($website->pages()->where('status', WebsitePageStatus::Published)->get() as $page) {
                $this->unpublishPage->execute($page);
            }

            $website->update(['status' => WebsiteStatus::Draft]);
        });

        return $website->fresh();
    }
}
