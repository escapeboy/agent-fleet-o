<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Website\Models\Website;
use App\Domain\Website\Services\WebsiteZipBuilder;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @tags Websites
 */
class ExportWebsiteController extends Controller
{
    public function __invoke(Request $request, Website $website, WebsiteZipBuilder $builder): StreamedResponse
    {
        $zipPath = $builder->build($website);

        $filename = 'website-'.Str::slug($website->name).'.zip';

        return response()->streamDownload(function () use ($zipPath) {
            readfile($zipPath);
            @unlink($zipPath);
        }, $filename, [
            'Content-Type' => 'application/zip',
        ]);
    }
}
