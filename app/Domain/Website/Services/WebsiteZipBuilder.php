<?php

namespace App\Domain\Website\Services;

use App\Domain\Website\Models\Website;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class WebsiteZipBuilder
{
    public function build(Website $website): string
    {
        $pages = $website->pages()->where('status', 'published')->orderBy('sort_order')->get();

        @mkdir(storage_path('app/tmp'), 0755, true);

        $zipPath = storage_path('app/tmp/website-'.$website->id.'-'.time().'.zip');

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to create ZIP archive at: '.$zipPath);
        }

        $indexAssigned = false;

        foreach ($pages as $page) {
            $html = $page->exported_html ?? '';

            // First page, or page with slug 'index'/'home', becomes index.html
            $isIndex = ! $indexAssigned && in_array($page->slug, ['index', 'home'], true);

            if (! $indexAssigned && ($isIndex || $pages->first()->is($page))) {
                $zip->addFromString('index.html', $html);
                $indexAssigned = true;
            } else {
                $safeSlug = basename(Str::slug($page->slug));
                $zip->addFromString('pages/'.$safeSlug.'.html', $html);
            }
        }

        $assets = $website->assets ?? collect();

        foreach ($assets as $asset) {
            try {
                $content = Storage::disk($asset->disk)->get($asset->path);
                $filename = basename($asset->path);
                $zip->addFromString('assets/'.$filename, $content);
            } catch (\Throwable) {
                // Skip assets that can't be retrieved
            }
        }

        $zip->close();

        return $zipPath;
    }
}
