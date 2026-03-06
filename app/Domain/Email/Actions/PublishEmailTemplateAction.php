<?php

namespace App\Domain\Email\Actions;

use App\Domain\Email\Models\EmailTemplate;
use App\Domain\Marketplace\Actions\PublishToMarketplaceAction;
use App\Domain\Marketplace\Enums\ListingVisibility;
use App\Domain\Marketplace\Models\MarketplaceListing;

class PublishEmailTemplateAction
{
    public function execute(
        EmailTemplate $template,
        string $teamId,
        string $userId,
        string $name,
        string $description,
        ?string $readme = null,
        ?string $category = null,
        array $tags = [],
        ListingVisibility $visibility = ListingVisibility::Public,
    ): MarketplaceListing {
        return app(PublishToMarketplaceAction::class)->execute(
            item: $template,
            teamId: $teamId,
            userId: $userId,
            name: $name,
            description: $description,
            readme: $readme,
            category: $category,
            tags: $tags,
            visibility: $visibility,
        );
    }
}
