<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Website\Actions\CreateWebsitePageAction;
use App\Domain\Website\Actions\DeleteWebsitePageAction;
use App\Domain\Website\Actions\PublishWebsitePageAction;
use App\Domain\Website\Actions\UpdateWebsitePageAction;
use App\Domain\Website\Enums\WebsitePageStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsitePage;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WebsitePageResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags Websites
 */
class WebsitePageController extends Controller
{
    public function index(Website $website): AnonymousResourceCollection
    {
        $pages = $website->pages()->orderBy('sort_order')->get();

        return WebsitePageResource::collection($pages);
    }

    public function show(Website $website, WebsitePage $page): WebsitePageResource
    {
        abort_if($page->website_id !== $website->id, 404);

        return new WebsitePageResource($page);
    }

    public function store(Request $request, Website $website, CreateWebsitePageAction $action): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string'],
            'title' => ['required', 'string'],
            'page_type' => ['sometimes', 'in:page,post,product,landing'],
            'status' => ['sometimes', 'in:draft,published'],
        ]);

        $page = $action->execute($website, $data);

        return (new WebsitePageResource($page))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Website $website, WebsitePage $page, UpdateWebsitePageAction $action): WebsitePageResource
    {
        abort_if($page->website_id !== $website->id, 404);

        $data = $request->validate([
            'slug' => ['sometimes', 'string'],
            'title' => ['sometimes', 'string'],
            'page_type' => ['sometimes', 'in:page,post,product,landing'],
            'status' => ['sometimes', 'in:draft,published'],
            'grapes_json' => ['sometimes', 'array'],
            'exported_html' => ['sometimes', 'string'],
            'exported_css' => ['sometimes', 'string'],
            'meta' => ['sometimes', 'array'],
        ]);

        $page = $action->execute($page, $data);

        return new WebsitePageResource($page);
    }

    public function publish(Website $website, WebsitePage $page, PublishWebsitePageAction $action): WebsitePageResource|JsonResponse
    {
        abort_if($page->website_id !== $website->id, 404);

        try {
            $page = $action->execute($page);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return new WebsitePageResource($page);
    }

    public function unpublish(Website $website, WebsitePage $page): WebsitePageResource
    {
        abort_if($page->website_id !== $website->id, 404);

        $page->update([
            'status' => WebsitePageStatus::Draft,
            'published_at' => null,
        ]);

        return new WebsitePageResource($page->fresh());
    }

    public function destroy(Website $website, WebsitePage $page, DeleteWebsitePageAction $action): JsonResponse
    {
        abort_if($page->website_id !== $website->id, 404);

        $action->execute($page);

        return response()->json(null, 204);
    }
}
