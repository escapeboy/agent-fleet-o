<?php

namespace App\Mcp\Tools\Marketplace;

use App\Domain\Agent\Models\Agent;
use App\Domain\Marketplace\Actions\PublishToMarketplaceAction;
use App\Domain\Skill\Models\Skill;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class MarketplacePublishTool extends Tool
{
    protected string $name = 'marketplace_publish';

    protected string $description = 'Publish a skill, agent, or workflow to the marketplace for others to install.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'entity_type' => $schema->string()
                ->description('Type of entity to publish: skill, agent, workflow')
                ->enum(['skill', 'agent', 'workflow'])
                ->required(),
            'entity_id' => $schema->string()
                ->description('UUID of the entity to publish')
                ->required(),
            'description' => $schema->string()
                ->description('Marketplace listing description'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'entity_type' => 'required|string|in:skill,agent,workflow',
            'entity_id' => 'required|string',
            'description' => 'nullable|string',
        ]);

        try {
            $entity = match ($validated['entity_type']) {
                'skill' => Skill::find($validated['entity_id']),
                'agent' => Agent::find($validated['entity_id']),
                'workflow' => Workflow::find($validated['entity_id']),
            };

            if (! $entity) {
                return Response::error(ucfirst($validated['entity_type']).' not found.');
            }

            $listing = app(PublishToMarketplaceAction::class)->execute(
                item: $entity,
                teamId: auth()->user()->current_team_id,
                userId: auth()->id(),
                name: $entity->name,
                description: $validated['description'] ?? $entity->description ?? '',
            );

            return Response::text(json_encode([
                'success' => true,
                'listing_id' => $listing->id,
                'slug' => $listing->slug,
                'status' => $listing->status->value,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
