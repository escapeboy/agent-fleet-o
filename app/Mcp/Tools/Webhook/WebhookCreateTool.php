<?php

namespace App\Mcp\Tools\Webhook;

use App\Domain\Webhook\Models\WebhookEndpoint;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WebhookCreateTool extends Tool
{
    protected string $name = 'webhook_create';

    protected string $description = 'Create a webhook endpoint. Events: experiment.completed, experiment.failed, project.run.completed, project.run.failed, approval.pending, budget.warning, or * for all.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Display name for the webhook')->required(),
            'url' => $schema->string()->description('URL to receive webhook payloads')->required(),
            'events' => $schema->array()
                ->description('Event types to subscribe to')
                ->items($schema->string())
                ->required(),
            'secret' => $schema->string()->description('HMAC secret (auto-generated if omitted)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;

        $endpoint = WebhookEndpoint::create([
            'team_id' => $teamId,
            'name' => $request->get('name'),
            'url' => $request->get('url'),
            'events' => $request->get('events'),
            'secret' => $request->get('secret', Str::random(64)),
            'retry_config' => ['max_retries' => 3, 'backoff' => 'exponential'],
        ]);

        return Response::text(json_encode([
            'id' => $endpoint->id,
            'name' => $endpoint->name,
            'url' => $endpoint->url,
            'events' => $endpoint->events,
            'is_active' => $endpoint->is_active,
        ]));
    }
}
