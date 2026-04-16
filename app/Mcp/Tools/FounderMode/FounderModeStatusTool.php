<?php

declare(strict_types=1);

namespace App\Mcp\Tools\FounderMode;

use App\Domain\Marketplace\Models\MarketplaceInstallation;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class FounderModeStatusTool extends Tool
{
    protected string $name = 'founder_mode_status';

    protected string $description = 'Report whether the Founder Mode marketplace pack is installed for the current team. Returns listing availability + installation timestamps + installed entity ids.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? Auth::user()?->current_team_id;

        if ($teamId === null) {
            return Response::error('No team context available.');
        }

        $listing = MarketplaceListing::withoutGlobalScopes()
            ->where('slug', 'founder-mode-pack')
            ->first();

        if (! $listing) {
            return Response::text(json_encode([
                'available' => false,
                'installed' => false,
                'reason' => 'Founder Mode pack has not been seeded on this instance.',
            ]));
        }

        $installation = MarketplaceInstallation::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('listing_id', $listing->id)
            ->orderByDesc('created_at')
            ->first();

        return Response::text(json_encode([
            'available' => true,
            'installed' => $installation !== null,
            'listing' => [
                'id' => $listing->id,
                'slug' => $listing->slug,
                'name' => $listing->name,
                'version' => $listing->version,
            ],
            'installation' => $installation ? [
                'id' => $installation->id,
                'installed_at' => $installation->created_at?->toIso8601String(),
                'installed_version' => $installation->installed_version,
                'installed_ids' => $installation->bundle_metadata['installed_ids'] ?? [],
            ] : null,
        ], JSON_UNESCAPED_SLASHES));
    }
}
