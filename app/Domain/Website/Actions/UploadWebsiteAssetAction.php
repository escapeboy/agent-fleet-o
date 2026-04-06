<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class UploadWebsiteAssetAction
{
    public function execute(Website $website, UploadedFile $file): WebsiteAsset
    {
        $path = $file->store("websites/{$website->id}", 'public');

        return WebsiteAsset::create([
            'website_id' => $website->id,
            'team_id' => $website->team_id,
            'filename' => mb_substr(str_replace("\0", '', $file->getClientOriginalName()), 0, 255),
            'disk' => 'public',
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => $file->getSize(),
        ]);
    }
}
