<?php

declare(strict_types=1);

namespace App\Mcp\Tools\FounderMode;

use App\Domain\Marketplace\Actions\InstallFromMarketplaceAction;
use App\Domain\Marketplace\Models\MarketplaceInstallation;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Shared\Exceptions\PlanLimitExceededException;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('destructive')]
class FounderModeInstallTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'founder_mode_install';

    protected string $description = 'Install the Founder Mode marketplace pack for the current team. Clones 6 persona agents, 20 framework-tagged skills, and 5 workflow templates. Idempotent: returns existing installation if already installed.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? Auth::user()?->current_team_id;
        $userId = Auth::user()?->id;

        if ($teamId === null || $userId === null) {
            return $this->permissionDeniedError('Authenticated team + user context required.');
        }

        $listing = MarketplaceListing::withoutGlobalScopes()
            ->where('slug', 'founder-mode-pack')
            ->first();

        if (! $listing) {
            return $this->failedPreconditionError('Founder Mode pack has not been seeded on this instance.');
        }

        $existing = MarketplaceInstallation::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('listing_id', $listing->id)
            ->orderByDesc('created_at')
            ->first();

        if ($existing) {
            return Response::text(json_encode([
                'installed' => true,
                'was_already_installed' => true,
                'installation_id' => $existing->id,
                'installed_ids' => $existing->bundle_metadata['installed_ids'] ?? [],
            ], JSON_UNESCAPED_SLASHES));
        }

        try {
            $installation = app(InstallFromMarketplaceAction::class)->execute($listing, $teamId, $userId);
        } catch (PlanLimitExceededException $e) {
            return $this->resourceExhaustedError('Plan limit exceeded: '.$e->getMessage());
        }

        return Response::text(json_encode([
            'installed' => true,
            'was_already_installed' => false,
            'installation_id' => $installation->id,
            'installed_ids' => $installation->bundle_metadata['installed_ids'] ?? [],
            'setup_hints' => $installation->bundle_metadata['setup_hints'] ?? [],
        ], JSON_UNESCAPED_SLASHES));
    }
}
