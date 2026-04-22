<?php

namespace App\Mcp\Tools\Marketplace;

use App\Domain\Marketplace\Jobs\ScanListingRiskJob;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsDestructive]
#[IsIdempotent]
#[AssistantTool('write')]
class MarketplaceRescanRiskTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'marketplace_rescan_risk';

    protected string $description = 'Trigger an AI security rescan for a marketplace listing. The scan runs asynchronously and updates the listing\'s risk_scan field.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'listing_id' => $schema->string()
                ->description('UUID of the marketplace listing to rescan')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'listing_id' => 'required|string|uuid',
        ]);

        $listing = MarketplaceListing::withoutGlobalScopes()->find($validated['listing_id']);

        if (! $listing) {
            return $this->notFoundError('listing');
        }

        if (! in_array($listing->type, ['skill', 'agent', 'workflow'], true)) {
            return $this->failedPreconditionError("Risk scanning is not supported for listing type: {$listing->type}");
        }

        ScanListingRiskJob::dispatch($listing->id)->onQueue('default');

        return Response::text(json_encode([
            'success' => true,
            'listing_id' => $listing->id,
            'message' => 'Risk scan queued. Results will appear in risk_scan field shortly.',
        ]));
    }
}
