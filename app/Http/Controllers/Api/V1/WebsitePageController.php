<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Website\Actions\CreateWebsitePageAction;
use App\Domain\Website\Actions\DeleteWebsitePageAction;
use App\Domain\Website\Actions\PublishWebsitePageAction;
use App\Domain\Website\Actions\UnpublishWebsitePageAction;
use App\Domain\Website\Actions\UpdateWebsitePageAction;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsitePage;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WebsitePageResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags Website Pages
 */
class WebsitePageController extends Controller
{
    public function index(Website $website): AnonymousResourceCollection
    {
        $pages = $website->pages()->orderBy('sort_order')->get();

        return WebsitePageResource::collection($pages);
    }

    public function show(Request $request, Website $website, WebsitePage $page): WebsitePageResource
    {
        abort_if($page->website_id !== $website->id, 404);

        return new WebsitePageResource($page);
    }

    public function store(Request $request, Website $website, CreateWebsitePageAction $action): JsonResponse
    {
        $request->validate([
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/'],
            'title' => ['required', 'string', 'max:255'],
            'page_type' => ['sometimes', 'string', 'in:page,post,product,landing'],
            'meta' => ['sometimes', 'array'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $page = $action->execute(
            website: $website,
            data: [
                'slug' => $request->input('slug'),
                'title' => $request->input('title'),
                'page_type' => $request->input('page_type', 'page'),
                'meta' => $request->input('meta', []),
                'sort_order' => $request->input('sort_order'),
            ],
        );

        return (new WebsitePageResource($page))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Website $website, WebsitePage $page, UpdateWebsitePageAction $action): WebsitePageResource
    {
        abort_if($page->website_id !== $website->id, 404);

        $request->validate([
            'slug' => ['sometimes', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/'],
            'title' => ['sometimes', 'string', 'max:255'],
            'grapes_json' => ['sometimes', 'nullable', 'array'],
            'exported_html' => ['sometimes', 'nullable', 'string'],
            'exported_css' => ['sometimes', 'nullable', 'string'],
            'meta' => ['sometimes', 'array'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $page = $action->execute(
            page: $page,
            data: array_filter([
                'slug' => $request->input('slug'),
                'title' => $request->input('title'),
                'grapes_json' => $request->input('grapes_json'),
                'exported_html' => $request->input('exported_html'),
                'exported_css' => $request->input('exported_css'),
                'meta' => $request->input('meta'),
                'sort_order' => $request->input('sort_order'),
            ], fn ($v) => $v !== null),
        );

        return new WebsitePageResource($page);
    }

    /**
     * @response 200 {"message": "Page deleted."}
     */
    public function destroy(Website $website, WebsitePage $page, DeleteWebsitePageAction $action): JsonResponse
    {
        abort_if($page->website_id !== $website->id, 404);

        $action->execute($page);

        return response()->json(['message' => 'Page deleted.']);
    }

    /**
     * @response 200 {"message": "Page published.", "data": {}}
     */
    public function publish(Website $website, WebsitePage $page, PublishWebsitePageAction $action): JsonResponse
    {
        abort_if($page->website_id !== $website->id, 404);

        $page = $action->execute($page);

        return response()->json([
            'message' => 'Page published.',
            'data' => new WebsitePageResource($page),
        ]);
    }

    /**
     * @response 200 {"message": "Page unpublished.", "data": {}}
     */
    public function unpublish(Website $website, WebsitePage $page, UnpublishWebsitePageAction $action): JsonResponse
    {
        abort_if($page->website_id !== $website->id, 404);

        $page = $action->execute($page);

        return response()->json([
            'message' => 'Page unpublished.',
            'data' => new WebsitePageResource($page),
        ]);
    }
}
