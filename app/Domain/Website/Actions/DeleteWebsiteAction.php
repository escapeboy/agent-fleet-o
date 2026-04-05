<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Models\Website;
use Illuminate\Support\Facades\Storage;

class DeleteWebsiteAction
{
    public function execute(Website $website): void
    {
        // Delete uploaded assets from storage
        foreach ($website->assets as $asset) {
            Storage::disk($asset->disk)->delete($asset->path);
        }

        $website->delete();
    }
}
