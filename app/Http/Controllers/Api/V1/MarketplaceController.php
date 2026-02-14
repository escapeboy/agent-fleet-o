<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Agent\Models\Agent;
use App\Domain\Marketplace\Actions\InstallFromMarketplaceAction;
use App\Domain\Marketplace\Actions\PublishToMarketplaceAction;
use App\Domain\Marketplace\Enums\ListingVisibility;
use App\Domain\Marketplace\Enums\MarketplaceStatus;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Marketplace\Models\MarketplaceReview;
use App\Domain\Skill\Models\Skill;
use App\Domain\Workflow\Models\Workflow;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MarketplaceListingResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class MarketplaceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $listings = QueryBuilder::for(
            MarketplaceListing::where('status', MarketplaceStatus::Published)
                ->where('visibility', ListingVisibility::Public),
        )
            ->allowedFilters([
                AllowedFilter::exact('type'),
                AllowedFilter::exact('category'),
                AllowedFilter::partial('name'),
            ])
            ->allowedSorts(['created_at', 'install_count', 'avg_rating', 'name'])
            ->defaultSort('-created_at')
            ->cursorPaginate($request->input('per_page', 15));

        return MarketplaceListingResource::collection($listings);
    }

    public function show(MarketplaceListing $listing): MarketplaceListingResource
    {
        $listing->load('reviews');

        return new MarketplaceListingResource($listing);
    }

    public function publish(Request $request, PublishToMarketplaceAction $action): JsonResponse
    {
        $request->validate([
            'type' => ['required', 'in:skill,agent,workflow'],
            'item_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'readme' => ['sometimes', 'nullable', 'string'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'tags' => ['sometimes', 'array'],
            'visibility' => ['sometimes', 'in:public,unlisted'],
        ]);

        $item = match ($request->type) {
            'skill' => Skill::findOrFail($request->item_id),
            'agent' => Agent::findOrFail($request->item_id),
            'workflow' => Workflow::findOrFail($request->item_id),
        };

        $listing = $action->execute(
            item: $item,
            teamId: $request->user()->current_team_id,
            userId: $request->user()->id,
            name: $request->name,
            description: $request->description,
            readme: $request->readme,
            category: $request->category,
            tags: $request->input('tags', []),
            visibility: ListingVisibility::from($request->input('visibility', 'public')),
        );

        return (new MarketplaceListingResource($listing))
            ->response()
            ->setStatusCode(201);
    }

    public function install(Request $request, MarketplaceListing $listing, InstallFromMarketplaceAction $action): JsonResponse
    {
        $installation = $action->execute(
            listing: $listing,
            teamId: $request->user()->current_team_id,
            userId: $request->user()->id,
        );

        return response()->json([
            'message' => 'Listing installed successfully.',
            'data' => [
                'installation_id' => $installation->id,
                'installed_version' => $installation->installed_version,
                'installed_skill_id' => $installation->installed_skill_id,
                'installed_agent_id' => $installation->installed_agent_id,
                'installed_workflow_id' => $installation->installed_workflow_id,
            ],
        ], 201);
    }

    public function review(Request $request, MarketplaceListing $listing): JsonResponse
    {
        $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $review = MarketplaceReview::create([
            'listing_id' => $listing->id,
            'user_id' => $request->user()->id,
            'team_id' => $request->user()->current_team_id,
            'rating' => $request->rating,
            'comment' => $request->comment,
        ]);

        // Update listing avg_rating
        $listing->update([
            'avg_rating' => $listing->reviews()->avg('rating'),
            'review_count' => $listing->reviews()->count(),
        ]);

        return response()->json([
            'data' => [
                'id' => $review->id,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'created_at' => $review->created_at->toISOString(),
            ],
        ], 201);
    }
}
