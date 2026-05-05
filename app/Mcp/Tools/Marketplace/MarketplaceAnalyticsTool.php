<?php

namespace App\Mcp\Tools\Marketplace;

use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Marketplace\Models\MarketplaceUsageRecord;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class MarketplaceAnalyticsTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'marketplace_analytics';

    protected string $description = 'Get usage analytics for a marketplace listing: total runs, success rate, avg cost, avg duration, usage trend (last 12 months), and monetization summary.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'listing_id' => $schema->string('Marketplace listing UUID'),
        ];
    }

    public function handle(Request $request): Response
    {
        $listingId = $request->get('listing_id');

        $listing = MarketplaceListing::withoutGlobalScopes()->findOrFail($listingId);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        // Only the team that published the listing may access its analytics.
        if ($listing->team_id !== $teamId) {
            return $this->notFoundError('listing');
        }

        $successRate = $listing->run_count > 0
            ? round(($listing->success_count / $listing->run_count) * 100, 1)
            : null;

        // Last 30 days breakdown
        $recent = MarketplaceUsageRecord::withoutGlobalScopes()
            ->where('listing_id', $listing->id)
            ->where('executed_at', '>=', now()->subDays(30))
            ->selectRaw('status, COUNT(*) as count, SUM(cost_credits) as total_cost')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $result = [
            'listing_id' => $listing->id,
            'name' => $listing->name,
            'type' => $listing->type,
            'install_count' => $listing->install_count,
            'run_count' => $listing->run_count,
            'success_count' => $listing->success_count,
            'success_rate_pct' => $successRate,
            'avg_cost_credits' => $listing->avg_cost_credits,
            'avg_duration_ms' => $listing->avg_duration_ms,
            'usage_trend_12m' => $listing->usage_trend ?? [],
            'last_30d' => [
                'completed' => (int) ($recent->get('completed')?->count ?? 0),
                'failed' => (int) ($recent->get('failed')?->count ?? 0),
                'total_cost_credits' => round((float) ($recent->get('completed')?->total_cost ?? 0), 4),
            ],
            'monetization' => [
                'enabled' => $listing->monetization_enabled,
                'price_per_run_credits' => $listing->price_per_run_credits,
            ],
        ];

        return Response::text(json_encode($result));
    }
}
