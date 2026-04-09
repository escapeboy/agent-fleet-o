<?php

namespace App\Mcp\Tools\Website;

use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteDeployment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class WebsiteDeploymentListTool extends Tool
{
    protected string $name = 'website_deployment_list';

    protected string $description = 'List recent deployments for a website with their status, URL, and build log.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'website_id' => $schema->string()->description('The website UUID'),
            'limit' => $schema->integer()->description('Max results (default 20, max 100)')->default(20),
        ];
    }

    public function handle(Request $request): Response
    {
        $website = Website::query()->find($request->get('website_id'));

        if (! $website) {
            return Response::text(json_encode(['error' => 'Website not found'], JSON_PRETTY_PRINT));
        }

        $limit = min((int) ($request->get('limit') ?? 20), 100);

        $deployments = $website->deployments()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return Response::text(json_encode([
            'website_id' => $website->id,
            'count' => $deployments->count(),
            'deployments' => $deployments->map(fn (WebsiteDeployment $d) => [
                'id' => $d->id,
                'provider' => $d->provider->value,
                'status' => $d->status->value,
                'url' => $d->url,
                'started_at' => $d->started_at?->toIso8601String(),
                'deployed_at' => $d->deployed_at?->toIso8601String(),
                'build_log' => $d->build_log,
                'created_at' => $d->created_at->toIso8601String(),
            ])->toArray(),
        ], JSON_PRETTY_PRINT));
    }
}
