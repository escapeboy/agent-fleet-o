<?php

namespace App\Mcp\Tools\Marketplace;

use App\Domain\Marketplace\Enums\MarketplaceStatus;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Marketplace\Models\MarketplaceReview;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class MarketplaceReviewTool extends Tool
{
    protected string $name = 'marketplace_review';

    protected string $description = 'Submit a review for a published marketplace listing. Rating must be 1–5 stars.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'listing_slug' => $schema->string()
                ->description('The marketplace listing slug')
                ->required(),
            'rating' => $schema->integer()
                ->description('Rating from 1 (poor) to 5 (excellent)')
                ->required(),
            'comment' => $schema->string()
                ->description('Optional review comment (max 1000 characters)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'listing_slug' => 'required|string',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'sometimes|nullable|string|max:1000',
        ]);

        $listing = MarketplaceListing::where('slug', $validated['listing_slug'])->first();

        if (! $listing) {
            return Response::error('Marketplace listing not found.');
        }

        if ($listing->status !== MarketplaceStatus::Published) {
            return Response::error('Listing is not published.');
        }

        try {
            $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

            $review = MarketplaceReview::create([
                'listing_id' => $listing->id,
                'user_id' => auth()->id(),
                'team_id' => $teamId,
                'rating' => $validated['rating'],
                'comment' => $validated['comment'] ?? null,
            ]);

            // Update listing aggregate stats
            $listing->update([
                'avg_rating' => $listing->reviews()->avg('rating'),
                'review_count' => $listing->reviews()->count(),
            ]);

            return Response::text(json_encode([
                'success' => true,
                'id' => $review->id,
                'listing_slug' => $listing->slug,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'created_at' => $review->created_at->toIso8601String(),
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
