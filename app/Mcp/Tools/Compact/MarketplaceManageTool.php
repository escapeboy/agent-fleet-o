<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Marketplace\MarketplaceAnalyticsTool;
use App\Mcp\Tools\Marketplace\MarketplaceBrowseTool;
use App\Mcp\Tools\Marketplace\MarketplaceCategoriesListTool;
use App\Mcp\Tools\Marketplace\MarketplaceInstallTool;
use App\Mcp\Tools\Marketplace\MarketplacePublishTool;
use App\Mcp\Tools\Marketplace\MarketplaceReviewTool;

class MarketplaceManageTool extends CompactTool
{
    protected string $name = 'marketplace_manage';

    protected string $description = 'Browse and manage marketplace listings. Actions: browse (query, category), publish (listing data), install (listing_slug), review (listing_slug, rating, comment), categories (list categories), analytics (listing_slug).';

    protected function toolMap(): array
    {
        return [
            'browse' => MarketplaceBrowseTool::class,
            'publish' => MarketplacePublishTool::class,
            'install' => MarketplaceInstallTool::class,
            'review' => MarketplaceReviewTool::class,
            'categories' => MarketplaceCategoriesListTool::class,
            'analytics' => MarketplaceAnalyticsTool::class,
        ];
    }
}
