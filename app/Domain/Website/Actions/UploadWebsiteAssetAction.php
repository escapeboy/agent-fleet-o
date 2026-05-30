<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteAsset;
use App\Infrastructure\Storage\TenantStorageManager;
use Illuminate\Http\UploadedFile;

class UploadWebsiteAssetAction
{
    public function __construct(private readonly TenantStorageManager $storage) {}

    public function execute(Website $website, UploadedFile $file): WebsiteAsset
    {
        // Website assets are served on published sites → public visibility.
        $key = $this->storage->put(
            $file,
            "website-assets/{$website->id}",
            TenantStorageManager::VISIBILITY_PUBLIC,
            $website->team_id,
        );

        return WebsiteAsset::create([
            'website_id' => $website->id,
            'team_id' => $website->team_id,
            'filename' => mb_substr(str_replace("\0", '', $file->getClientOriginalName()), 0, 255),
            'disk' => $this->storage->diskName(TenantStorageManager::VISIBILITY_PUBLIC),
            'path' => $key,
            'url' => $this->storage->publicUrl($key),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => $file->getSize(),
        ]);
    }
}
