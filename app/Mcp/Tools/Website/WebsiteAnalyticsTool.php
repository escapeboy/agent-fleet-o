<?php

namespace App\Mcp\Tools\Website;

use App\Domain\Website\Models\Website;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class WebsiteAnalyticsTool extends Tool
{
    protected string $name = 'website_analytics';

    protected string $description = 'Get analytics for a website — page count, published pages, asset count, last deployment.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'website_id' => $schema->string()->description('The website UUID (required)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $website = Website::withCount(['pages', 'assets'])->find($request->get('website_id'));

        if (! $website) {
            return Response::text(json_encode(['error' => 'Website not found'], JSON_PRETTY_PRINT));
        }

        $publishedPages = $website->pages()->where('status', 'published')->count();
        $lastDeployment = $website->deployments()->latest()->first();

        return Response::text(json_encode([
            'website_id' => $website->id,
            'name' => $website->name,
            'total_pages' => $website->pages_count,
            'published_pages' => $publishedPages,
            'asset_count' => $website->assets_count,
            'last_deployment_status' => $lastDeployment?->status instanceof \BackedEnum
                ? $lastDeployment->status->value
                : $lastDeployment?->status,
            'last_deployed_at' => $lastDeployment?->created_at?->toIso8601String(),
        ], JSON_PRETTY_PRINT));
    }
}
