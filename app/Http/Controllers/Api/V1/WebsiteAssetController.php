<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteAsset;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * @tags Websites
 */
class WebsiteAssetController extends Controller
{
    public function index(Website $website): JsonResponse
    {
        $assets = $website->assets->map(fn (WebsiteAsset $asset) => [
            'id' => $asset->id,
            'filename' => $asset->filename,
            'url' => $asset->url,
            'mime_type' => $asset->mime_type,
            'size_bytes' => $asset->size_bytes,
            'created_at' => $asset->created_at->toISOString(),
        ])->values();

        return response()->json($assets);
    }

    public function store(Request $request, Website $website): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240',
                'mimes:jpg,jpeg,png,gif,webp,ico,css,js,woff,woff2,ttf,eot,pdf'],
        ]);

        $file = $request->file('file');
        $path = $file->store('website-assets/'.$website->id);

        $url = Storage::url($path);

        $asset = WebsiteAsset::create([
            'website_id' => $website->id,
            'team_id' => $website->team_id,
            'filename' => $file->getClientOriginalName(),
            'disk' => config('filesystems.default'),
            'path' => $path,
            'url' => $url,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
        ]);

        return response()->json([
            'id' => $asset->id,
            'filename' => $asset->filename,
            'url' => $asset->url,
            'mime_type' => $asset->mime_type,
            'size_bytes' => $asset->size_bytes,
            'created_at' => $asset->created_at->toISOString(),
        ], 201);
    }

    public function destroy(Website $website, WebsiteAsset $asset): JsonResponse
    {
        abort_if($asset->website_id !== $website->id, 404);

        Storage::disk($asset->disk)->delete($asset->path);

        $asset->delete();

        return response()->json(null, 204);
    }
}
