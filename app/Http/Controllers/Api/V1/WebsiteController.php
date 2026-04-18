<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Website\Actions\CreateWebsiteAction;
use App\Domain\Website\Actions\DeleteWebsiteAction;
use App\Domain\Website\Actions\DeployWebsiteAction;
use App\Domain\Website\Actions\PublishWebsitePageAction;
use App\Domain\Website\Actions\UnpublishWebsiteAction;
use App\Domain\Website\Actions\UpdateWebsiteAction;
use App\Domain\Website\Enums\DeploymentProvider;
use App\Domain\Website\Enums\WebsitePageStatus;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Exceptions\DeploymentDriverException;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteDeployment;
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

    public function publish(Website $website, PublishWebsitePageAction $publishPage): WebsiteResource
    {
        $publishedCount = 0;
        $skippedCount = 0;

        foreach ($website->pages()->where('status', WebsitePageStatus::Draft)->get() as $page) {
            if (empty($page->exported_html)) {
                $skippedCount++;

                continue;
            }

            try {
                $publishPage->execute($page);
                $publishedCount++;
            } catch (\RuntimeException) {
                $skippedCount++;
            }
        }

        $website->update(['status' => WebsiteStatus::Published]);

        return new WebsiteResource($website->fresh()->load(['pages' => fn ($q) => $q->orderBy('sort_order')]));
    }

    public function unpublish(Website $website, UnpublishWebsiteAction $action): WebsiteResource
    {
        $website = $action->execute($website);

        return new WebsiteResource($website->load(['pages' => fn ($q) => $q->orderBy('sort_order')]));
    }

    public function deploy(Request $request, Website $website, DeployWebsiteAction $action): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string'],
            'config' => ['sometimes', 'array'],
        ]);

        $provider = DeploymentProvider::tryFrom($data['provider']);
        if (! $provider) {
            return response()->json(['error' => "Unknown deployment provider '{$data['provider']}'."], 422);
        }

        try {
            $deployment = $action->execute($website, $provider, $data['config'] ?? []);
        } catch (DeploymentDriverException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'id' => $deployment->id,
            'status' => $deployment->status->value,
            'provider' => $deployment->provider->value,
            'queued_at' => $deployment->created_at->toIso8601String(),
        ], 202);
    }

    public function deployments(Website $website): JsonResponse
    {
        $deployments = $website->deployments()->orderByDesc('created_at')->limit(50)->get();

        return response()->json([
            'data' => $deployments->map(fn (WebsiteDeployment $d) => [
                'id' => $d->id,
                'provider' => $d->provider->value,
                'status' => $d->status->value,
                'url' => $d->url,
                'started_at' => $d->started_at?->toIso8601String(),
                'deployed_at' => $d->deployed_at?->toIso8601String(),
                'build_log' => $d->build_log,
                'created_at' => $d->created_at->toIso8601String(),
            ]),
        ]);
    }

    public function deployment(Website $website, WebsiteDeployment $deployment): JsonResponse
    {
        abort_if($deployment->website_id !== $website->id, 404);

        return response()->json([
            'id' => $deployment->id,
            'provider' => $deployment->provider->value,
            'status' => $deployment->status->value,
            'url' => $deployment->url,
            'config' => $deployment->config,
            'started_at' => $deployment->started_at?->toIso8601String(),
            'deployed_at' => $deployment->deployed_at?->toIso8601String(),
            'build_log' => $deployment->build_log,
            'created_at' => $deployment->created_at->toIso8601String(),
        ]);
    }
}
