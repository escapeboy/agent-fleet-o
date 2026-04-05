<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Website\Actions\CreateWebsiteAction;
use App\Domain\Website\Actions\DeleteWebsiteAction;
use App\Domain\Website\Actions\GenerateWebsiteFromPromptAction;
use App\Domain\Website\Actions\UpdateWebsiteAction;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Services\WebsiteZipBuilder;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WebsiteResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
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
            ->allowedSorts(['created_at', 'updated_at', 'name'])
            ->defaultSort('-created_at')
            ->withCount('pages')
            ->cursorPaginate(min((int) $request->input('per_page', 15), 100));

        return WebsiteResource::collection($websites);
    }

    public function show(Website $website): WebsiteResource
    {
        $website->load('pages');

        return new WebsiteResource($website);
    }

    public function store(Request $request, CreateWebsiteAction $action): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/'],
            'custom_domain' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings' => ['sometimes', 'array'],
        ]);

        $website = $action->execute(
            teamId: $request->user()->current_team_id,
            name: $request->input('name'),
            data: [
                'slug' => $request->input('slug'),
                'custom_domain' => $request->input('custom_domain'),
                'settings' => $request->input('settings', []),
            ],
        );

        return (new WebsiteResource($website))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Website $website, UpdateWebsiteAction $action): WebsiteResource
    {
        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/'],
            'status' => ['sometimes', 'string', 'in:draft,published,archived'],
            'custom_domain' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings' => ['sometimes', 'array'],
        ]);

        $website = $action->execute(
            website: $website,
            data: array_filter([
                'name' => $request->input('name'),
                'slug' => $request->input('slug'),
                'status' => $request->input('status'),
                'custom_domain' => $request->input('custom_domain'),
                'settings' => $request->input('settings'),
            ], fn ($v) => $v !== null),
        );

        return new WebsiteResource($website);
    }

    /**
     * @response 200 {"message": "Website deleted."}
     */
    public function destroy(Website $website, DeleteWebsiteAction $action): JsonResponse
    {
        $action->execute($website);

        return response()->json(['message' => 'Website deleted.']);
    }

    /**
     * Generate a website from a natural language prompt using AI.
     *
     * @response 201 {"data": {}}
     */
    public function generate(Request $request, GenerateWebsiteFromPromptAction $action): JsonResponse
    {
        $request->validate([
            'prompt' => ['required', 'string', 'max:2000'],
        ]);

        $website = $action->execute(
            teamId: $request->user()->current_team_id,
            prompt: $request->input('prompt'),
        );

        return (new WebsiteResource($website->load('pages')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Export the website as a deployable ZIP archive.
     *
     * @response 200 binary ZIP file
     */
    public function export(Website $website, WebsiteZipBuilder $builder): Response
    {
        $website->load('pages');

        $zipPath = $builder->build($website);

        return response()->download($zipPath, $website->slug.'.zip', [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend();
    }
}
