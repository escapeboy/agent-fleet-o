<?php

namespace App\Mcp\Tools\Website;

use App\Domain\Website\Actions\DeployWebsiteAction;
use App\Domain\Website\Enums\DeploymentProvider;
use App\Domain\Website\Exceptions\DeploymentDriverException;
use App\Domain\Website\Models\Website;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class WebsiteDeployTool extends Tool
{
    protected string $name = 'website_deploy';

    protected string $description = 'Deploy a published website to a target provider (zip, vercel). Queues a background job and returns the deployment id for polling.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'website_id' => $schema->string()->description('The website UUID to deploy'),
            'provider' => $schema->string()
                ->description('Deployment target')
                ->enum(['zip', 'vercel', 'netlify', 'cloudflare', 'manual']),
        ];
    }

    public function handle(Request $request): Response
    {
        $website = Website::query()->find($request->get('website_id'));

        if (! $website) {
            return Response::text(json_encode(['error' => 'Website not found'], JSON_PRETTY_PRINT));
        }

        $provider = DeploymentProvider::tryFrom((string) $request->get('provider', ''));
        if (! $provider) {
            return Response::text(json_encode(['error' => 'Unknown deployment provider'], JSON_PRETTY_PRINT));
        }

        try {
            $deployment = app(DeployWebsiteAction::class)->execute($website, $provider);
        } catch (DeploymentDriverException $e) {
            return Response::text(json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT));
        }

        return Response::text(json_encode([
            'success' => true,
            'deployment_id' => $deployment->id,
            'provider' => $deployment->provider->value,
            'status' => $deployment->status->value,
            'message' => 'Deployment queued. Poll website_deployment_list or website_deployment_get for progress.',
        ], JSON_PRETTY_PRINT));
    }
}
