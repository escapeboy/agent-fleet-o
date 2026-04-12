<?php

namespace App\Mcp\Tools\Marketplace;

use App\Domain\Marketplace\Enums\ListingVisibility;
use App\Domain\Marketplace\Enums\MarketplaceStatus;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class MarketplaceCategoriesListTool extends Tool
{
    protected string $name = 'marketplace_categories';

    protected string $description = 'List all categories available in the marketplace with the count of published listings in each category.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $categories = MarketplaceListing::query()
            ->where('status', MarketplaceStatus::Published)
            ->where('visibility', ListingVisibility::Public)
            ->whereNotNull('category')
            ->select('category')
            ->selectRaw('count(*) as count')
            ->groupBy('category')
            ->orderByDesc('count')
            ->get();

        return Response::text(json_encode([
            'count' => $categories->count(),
            'categories' => $categories->map(fn (MarketplaceListing $c) => [
                'name' => $c->category,
                'listing_count' => $c->getAttribute('count'),
            ])->toArray(),
        ]));
    }
}
