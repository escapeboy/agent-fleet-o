<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Website\Actions\CreateWebsiteAction;
use App\Domain\Website\Actions\DeleteWebsiteAction;
use App\Domain\Website\Actions\UpdateWebsiteAction;
use App\Domain\Website\Models\Website;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WebsiteResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @tags Websites
 */
class WebsiteController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $websites = QueryBuilder::for(Website::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::partial('name'),
            ])
            ->allowedSorts(['created_at', 'name'])
            ->defaultSort('-created_at')
            ->cursorPaginate(min((int) $request->input('per_page', 15), 100));

        return WebsiteResource::collection($websites);
    }

    public function show(Website $website): WebsiteResource
    {
        $website->load(['pages' => fn ($q) => $q->orderBy('sort_order')]);

        return new WebsiteResource($website);
    }

    public function store(Request $request, CreateWebsiteAction $action): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['sometimes', 'string'],
            'status' => ['sometimes', 'in:draft,published,archived'],
            'settings' => ['sometimes', 'array'],
            'custom_domain' => ['nullable', 'string', 'max:253', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9\-\.]+[a-zA-Z0-9]$/'],
        ]);

        $team = $request->user()->currentTeam;

        $website = $action->execute($team, $data, $request->user());

        return (new WebsiteResource($website))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Website $website, UpdateWebsiteAction $action): WebsiteResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string'],
            'status' => ['sometimes', 'in:draft,published,archived'],
            'settings' => ['sometimes', 'array'],
            'custom_domain' => ['nullable', 'string', 'max:253', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9\-\.]+[a-zA-Z0-9]$/'],
        ]);

        $website = $action->execute($website, $data);

        return new WebsiteResource($website);
    }

    public function destroy(Website $website, DeleteWebsiteAction $action): JsonResponse
    {
        $action->execute($website);

        return response()->json(null, 204);
    }
}
