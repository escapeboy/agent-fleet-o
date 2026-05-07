<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Marketplace\MarketplaceAnalyticsTool;
use App\Mcp\Tools\Marketplace\MarketplaceBrowseTool;
use App\Mcp\Tools\Marketplace\MarketplaceCategoriesListTool;
use App\Mcp\Tools\Marketplace\MarketplaceInstallTool;
use App\Mcp\Tools\Marketplace\MarketplacePublishTool;
use App\Mcp\Tools\Marketplace\MarketplaceReviewTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class MarketplaceManageTool extends CompactTool
{
    protected string $name = 'marketplace_manage';

    protected string $description = <<<'TXT'
Cross-team marketplace for shared skills, agents, and workflows. `browse` and `categories` are public (no auth scope); `publish`, `install`, `review` operate within the caller's team. Installing a listing copies the artifact into the team and increments the listing's install count.

Actions:
- browse (read) — optional: query, category, sort. Public.
- categories (read) — taxonomy of available categories. Public.
- publish (write) — listing data: target_type (skill/agent/workflow), target_id, name, description, visibility (public/private/team).
- install (write) — listing_slug. Copies the listed entity into your team.
- review (write) — listing_slug, rating (1-5), comment. One review per user per listing.
- analytics (read) — listing_slug. Install counts, ratings (publisher only).
TXT;

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
