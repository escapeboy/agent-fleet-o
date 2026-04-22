<?php

namespace App\Mcp\Tools\Marketplace;

use App\Domain\Marketplace\Actions\InstallFromMarketplaceAction;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class MarketplaceInstallTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'marketplace_install';

    protected string $description = 'Install a marketplace listing into your team workspace. Clones the skill/agent/workflow/bundle with your team ownership.';

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
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $listing = MarketplaceListing::where('slug', $validated['listing_slug'])->first();

        if (! $listing) {
            return $this->notFoundError('marketplace listing');
        }

        try {
            $installation = app(InstallFromMarketplaceAction::class)->execute(
                listing: $listing,
                teamId: $teamId,
                userId: auth()->id(),
            );

            return Response::text(json_encode([
                'success' => true,
                'installation_id' => $installation->id,
                'listing_slug' => $listing->slug,
                'installed_version' => $installation->installed_version,
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
