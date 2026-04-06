<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Website\Actions\UploadWebsiteAssetAction;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteAsset;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * @tags Website Assets
 */
class WebsiteAssetController extends Controller
{
    public function index(Website $website): JsonResponse
    {
        $assets = $website->assets()->orderByDesc('created_at')->get();

        return response()->json([
            'data' => $assets->map(fn (WebsiteAsset $a) => [
                'id' => $a->id,
                'filename' => $a->filename,
                'url' => $a->url,
                'mime_type' => $a->mime_type,
                'size_bytes' => $a->size_bytes,
                'created_at' => $a->created_at->toISOString(),
            ]),
        ]);
    }

    public function store(Request $request, Website $website, UploadWebsiteAssetAction $action): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'image', 'max:5120'],
        ]);

        $asset = $action->execute($website, $request->file('file'));

        return response()->json([
            'id' => $asset->id,
            'filename' => $asset->filename,
            'url' => $asset->url,
            'mime_type' => $asset->mime_type,
            'size_bytes' => $asset->size_bytes,
        ], 201);
    }

    public function destroy(Website $website, WebsiteAsset $asset): JsonResponse
    {
        abort_if($asset->website_id !== $website->id, 404);

        Storage::disk($asset->disk)->delete($asset->path);
        $asset->delete();

        return response()->json(['message' => 'Asset deleted.']);
    }
}
