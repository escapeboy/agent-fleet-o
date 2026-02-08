<?php

namespace App\Domain\Marketplace\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Marketplace\Enums\ListingVisibility;
use App\Domain\Marketplace\Enums\MarketplaceStatus;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Skill\Models\Skill;
use Illuminate\Support\Str;

class PublishToMarketplaceAction
{
    /**
     * Publish a skill or agent to the marketplace.
     */
    public function execute(
        Skill|Agent $item,
        string $teamId,
        string $userId,
        string $name,
        string $description,
        ?string $readme = null,
        ?string $category = null,
        array $tags = [],
        ListingVisibility $visibility = ListingVisibility::Public,
    ): MarketplaceListing {
        $type = $item instanceof Skill ? 'skill' : 'agent';
        $version = $item instanceof Skill ? ($item->current_version ?? '1.0.0') : '1.0.0';

        $configSnapshot = $item instanceof Skill
            ? [
                'type' => $item->type->value,
                'input_schema' => $item->input_schema,
                'output_schema' => $item->output_schema,
                'configuration' => $item->configuration,
                'system_prompt' => $item->system_prompt,
                'risk_level' => $item->risk_level->value,
            ]
            : [
                'role' => $item->role,
                'goal' => $item->goal,
                'provider' => $item->provider,
                'model' => $item->model,
                'capabilities' => $item->capabilities,
                'constraints' => $item->constraints,
            ];

        return MarketplaceListing::create([
            'team_id' => $teamId,
            'published_by' => $userId,
            'type' => $type,
            'listable_id' => $item->id,
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::random(6),
            'description' => $description,
            'readme' => $readme,
            'category' => $category,
            'tags' => $tags,
            'status' => MarketplaceStatus::Published,
            'visibility' => $visibility,
            'version' => $version,
            'configuration_snapshot' => $configSnapshot,
        ]);
    }
}
