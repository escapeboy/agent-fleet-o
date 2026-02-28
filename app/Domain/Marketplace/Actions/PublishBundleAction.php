<?php

namespace App\Domain\Marketplace\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Marketplace\Enums\ListingVisibility;
use App\Domain\Marketplace\Enums\MarketplaceStatus;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Skill\Models\Skill;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Support\Str;

class PublishBundleAction
{
    private PublishToMarketplaceAction $publisher;

    public function __construct(PublishToMarketplaceAction $publisher)
    {
        $this->publisher = $publisher;
    }

    /**
     * Publish a bundle of skills, agents, and/or workflows as a single marketplace listing.
     *
     * @param  array<array{type: string, id: string}>  $items
     */
    public function execute(
        string $teamId,
        string $userId,
        string $name,
        string $description,
        array $items,
        ?string $readme = null,
        ?string $category = null,
        array $tags = [],
        ListingVisibility $visibility = ListingVisibility::Public,
    ): MarketplaceListing {
        $configSnapshot = ['items' => []];

        foreach ($items as $item) {
            $entity = match ($item['type']) {
                'skill' => Skill::findOrFail($item['id']),
                'agent' => Agent::findOrFail($item['id']),
                'workflow' => Workflow::findOrFail($item['id']),
                default => throw new \InvalidArgumentException("Unsupported bundle item type: {$item['type']}"),
            };

            $snapshot = match ($item['type']) {
                'skill' => [
                    'type' => $entity->type->value,
                    'input_schema' => $entity->input_schema,
                    'output_schema' => $entity->output_schema,
                    'configuration' => $entity->configuration,
                    'system_prompt' => $entity->system_prompt,
                    'risk_level' => $entity->risk_level->value,
                ],
                'agent' => [
                    'role' => $entity->role,
                    'goal' => $entity->goal,
                    'provider' => $entity->provider,
                    'model' => $entity->model,
                    'capabilities' => $entity->capabilities,
                    'constraints' => $entity->constraints,
                ],
                'workflow' => $this->publisher->snapshotWorkflow($entity),
            };

            $configSnapshot['items'][] = [
                'type' => $item['type'],
                'name' => $entity->name,
                'description' => $entity->description ?? '',
                'snapshot' => $snapshot,
            ];
        }

        return MarketplaceListing::create([
            'team_id' => $teamId,
            'published_by' => $userId,
            'type' => 'bundle',
            'listable_id' => null,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'description' => $description,
            'readme' => $readme,
            'category' => $category,
            'tags' => $tags,
            'status' => MarketplaceStatus::Published,
            'visibility' => $visibility,
            'version' => '1.0.0',
            'configuration_snapshot' => $configSnapshot,
        ]);
    }
}
