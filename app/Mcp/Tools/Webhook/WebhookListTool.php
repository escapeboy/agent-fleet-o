<?php

namespace App\Mcp\Tools\Webhook;

use App\Domain\Webhook\Models\WebhookEndpoint;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class WebhookListTool extends Tool
{
    protected string $name = 'webhook_list';

    protected string $description = 'List webhook endpoints. Returns id, name, url, events, is_active, failure_count.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'active_only' => $schema->boolean()
                ->description('Only return active endpoints')
                ->default(false),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = WebhookEndpoint::query()->orderBy('name');

        if ($request->get('active_only')) {
            $query->where('is_active', true);
        }

        $endpoints = $query->get();

        return Response::text(json_encode([
            'count' => $endpoints->count(),
            'endpoints' => $endpoints->map(function (WebhookEndpoint $e): array {
                /** @var Carbon|null $lastTriggeredAt */
                $lastTriggeredAt = $e->last_triggered_at;

                return [
                    'id' => $e->id,
                    'name' => $e->name,
                    'url' => $e->url,
                    'events' => $e->events,
                    'is_active' => $e->is_active,
                    'failure_count' => $e->failure_count,
                    'last_triggered_at' => $lastTriggeredAt?->toIso8601String(),
                ];
            })->toArray(),
        ]));
    }
}
