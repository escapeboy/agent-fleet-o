<?php

namespace App\Mcp\Tools\Marketplace;

use App\Domain\Marketplace\Enums\MarketplaceStatus;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('destructive')]
class MarketplaceUnpublishTool extends Tool
{
    protected string $name = 'marketplace_unpublish';

    protected string $description = 'Unpublish a marketplace listing by setting its status to Suspended.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'listing_id' => $schema->string()->description('The marketplace listing ID to unpublish.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $listing = MarketplaceListing::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($request->get('listing_id'));

        if (! $listing) {
            return Response::error('Marketplace listing not found.');
        }

        $listing->status = MarketplaceStatus::Suspended;
        $listing->save();

        return Response::text(json_encode([
            'success' => true,
            'id' => $listing->id,
            'slug' => $listing->slug,
            'status' => $listing->status->value,
        ]));
    }
}
