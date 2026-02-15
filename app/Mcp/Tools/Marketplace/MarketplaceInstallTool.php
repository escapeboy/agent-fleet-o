<?php

namespace App\Mcp\Tools\Marketplace;

use App\Domain\Marketplace\Actions\InstallFromMarketplaceAction;
use App\Domain\Marketplace\Models\MarketplaceListing;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class MarketplaceInstallTool extends Tool
{
    protected string $name = 'marketplace_install';

    protected string $description = 'Install a marketplace listing into your team workspace. Clones the skill/agent/workflow with your team ownership.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'listing_slug' => $schema->string()
                ->description('The marketplace listing slug')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'listing_slug' => 'required|string',
        ]);

        $listing = MarketplaceListing::where('slug', $validated['listing_slug'])->first();

        if (! $listing) {
            return Response::error('Marketplace listing not found.');
        }

        try {
            $installation = app(InstallFromMarketplaceAction::class)->execute(
                listing: $listing,
                teamId: auth()->user()->current_team_id,
                userId: auth()->id(),
            );

            return Response::text(json_encode([
                'success' => true,
                'installation_id' => $installation->id,
                'listing_slug' => $listing->slug,
                'installed_version' => $installation->installed_version,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
