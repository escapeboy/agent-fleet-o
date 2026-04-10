<?php

namespace App\Mcp\Tools\Marketplace;

use App\Domain\Agent\Models\Agent;
use App\Domain\Marketplace\Actions\PublishBundleAction;
use App\Domain\Marketplace\Actions\PublishToMarketplaceAction;
use App\Domain\Marketplace\Enums\ListingVisibility;
use App\Domain\Skill\Models\Skill;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class MarketplacePublishTool extends Tool
{
    protected string $name = 'marketplace_publish';

    protected string $description = 'Publish a skill, agent, workflow, or bundle to the marketplace.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'entity_type' => $schema->string()
                ->description('Type of entity to publish: skill, agent, workflow, or bundle')
                ->enum(['skill', 'agent', 'workflow', 'bundle'])
                ->required(),
            'entity_id' => $schema->string()
                ->description('UUID of the entity to publish (not required for bundle)'),
            'bundle_items' => $schema->array()
                ->description('For bundle type: array of {type, id} objects. e.g. [{"type":"skill","id":"uuid"},{"type":"agent","id":"uuid"}]'),
            'name' => $schema->string()
                ->description('Marketplace listing name (required for bundle)'),
            'description' => $schema->string()
                ->description('Marketplace listing description'),
            'visibility' => $schema->string()
                ->description('Listing visibility: public (all users), unlisted (direct link only), team (team members only). Default: public')
                ->enum(['public', 'unlisted', 'team'])
                ->default('public'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'entity_type' => 'required|string|in:skill,agent,workflow,bundle',
            'entity_id' => 'nullable|string',
            'bundle_items' => 'nullable|array',
            'bundle_items.*.type' => 'string|in:skill,agent,workflow',
            'bundle_items.*.id' => 'string',
            'name' => 'nullable|string',
            'description' => 'nullable|string',
            'visibility' => 'nullable|string|in:public,unlisted,team',
        ]);

        try {
            $visibility = ListingVisibility::from($validated['visibility'] ?? 'public');
            $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
            $userId = auth()->id();

            if ($validated['entity_type'] === 'bundle') {
                $items = $validated['bundle_items'] ?? [];
                if (count($items) < 2) {
                    return Response::error('A bundle requires at least 2 items.');
                }

                $listing = app(PublishBundleAction::class)->execute(
                    teamId: $teamId,
                    userId: $userId,
                    name: $validated['name'] ?? 'Bundle',
                    description: $validated['description'] ?? '',
                    items: $items,
                    visibility: $visibility,
                );
            } else {
                $entity = match ($validated['entity_type']) {
                    'skill' => Skill::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['entity_id']),
                    'agent' => Agent::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['entity_id']),
                    'workflow' => Workflow::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['entity_id']),
                };

                if (! $entity) {
                    return Response::error(ucfirst($validated['entity_type']).' not found.');
                }

                $listing = app(PublishToMarketplaceAction::class)->execute(
                    item: $entity,
                    teamId: $teamId,
                    userId: $userId,
                    name: $validated['name'] ?? $entity->name,
                    description: $validated['description'] ?? $entity->description ?? '',
                    visibility: $visibility,
                );
            }

            return Response::text(json_encode([
                'success' => true,
                'listing_id' => $listing->id,
                'slug' => $listing->slug,
                'status' => $listing->status->value,
                'type' => $listing->type,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
