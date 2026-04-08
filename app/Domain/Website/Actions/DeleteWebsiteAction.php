<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Models\Website;
use Illuminate\Support\Facades\Log;

class DeleteWebsiteAction
{
    public function execute(Website $website): void
    {
        $website->delete();

        Log::info('Website deleted', ['website_id' => $website->id, 'team_id' => $website->team_id]);
    }
}
