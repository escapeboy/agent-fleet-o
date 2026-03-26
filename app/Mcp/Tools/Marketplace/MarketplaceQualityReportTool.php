<?php

namespace App\Mcp\Tools\Marketplace;

use App\Domain\Marketplace\Enums\MarketplaceStatus;
use App\Domain\Marketplace\Models\MarketplaceListing;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Returns community quality scores for published marketplace listings,
 * sorted by quality descending. Listings are cross-team (withoutGlobalScopes)
 * since marketplace data is intentionally public. A verified listing has a
 * community_quality_score >= 0.75.
 */
#[IsReadOnly]
#[IsIdempotent]
class MarketplaceQualityReportTool extends Tool
{
    protected string $name = 'marketplace_quality_report';

    protected string $description = 'Get community quality scores for marketplace listings, sorted by quality.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('Max listings to return (default 20, max 100)')
                ->default(20),
            'verified_only' => $schema->boolean()
                ->description('Only return verified quality listings (community_quality_score >= 0.75)')
                ->default(false),
        ];
    }

    public function handle(Request $request): Response
    {
        $limit = min((int) ($request->get('limit', 20)), 100);
        $verifiedOnly = (bool) $request->get('verified_only', false);

        // withoutGlobalScopes: marketplace listings are cross-team public data
        $query = MarketplaceListing::withoutGlobalScopes()
            ->where('status', MarketplaceStatus::Published)
            ->whereNotNull('community_quality_score')
            ->orderByDesc('community_quality_score');

        if ($verifiedOnly) {
            $query->where('community_quality_score', '>=', 0.75);
        }

        $listings = $query->limit($limit)->get([
            'id', 'name', 'slug', 'type', 'community_quality_score',
            'install_success_rate', 'community_reliability_rate', 'install_count',
            'quality_computed_at',
        ]);

        if ($listings->isEmpty()) {
            return Response::text(json_encode(['count' => 0, 'listings' => []]));
        }

        $report = $listings->map(fn ($l) => [
            'name' => $l->name,
            'slug' => $l->slug,
            'type' => $l->type,
            'community_quality_score' => round((float) $l->community_quality_score * 100, 1).'%',
            'install_success_rate' => $l->install_success_rate !== null
                ? round((float) $l->install_success_rate * 100, 1).'%'
                : null,
            'community_reliability_rate' => $l->community_reliability_rate !== null
                ? round((float) $l->community_reliability_rate * 100, 1).'%'
                : null,
            'install_count' => $l->install_count,
            'verified' => (float) $l->community_quality_score >= 0.75,
            'quality_computed_at' => $l->quality_computed_at?->toIso8601String(),
        ])->values()->toArray();

        return Response::text(json_encode(['count' => count($report), 'listings' => $report]));
    }
}
