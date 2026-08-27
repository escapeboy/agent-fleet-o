<?php

namespace App\Mcp\Tools\Webhook;

use App\Domain\Webhook\Models\WebhookEndpoint;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class WebhookGetTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'webhook_get';

    protected string $description = 'Get one webhook endpoint including delivery config and failure counters. The signing secret is never returned.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'webhook_id' => $schema->string()
                ->description('The webhook endpoint UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $endpoint = WebhookEndpoint::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($request->get('webhook_id'));

        if (! $endpoint) {
            return $this->notFoundError('webhook endpoint');
        }

        /** @var Carbon|null $lastTriggeredAt */
        $lastTriggeredAt = $endpoint->last_triggered_at;

        // `secret` is a TeamEncryptedString and is deliberately omitted — an
        // LLM-visible response must never carry the HMAC signing key.
        return Response::text(json_encode([
            'id' => $endpoint->id,
            'name' => $endpoint->name,
            'url' => $endpoint->url,
            'events' => $endpoint->events ?? [],
            'is_active' => $endpoint->is_active,
            'headers' => $endpoint->headers ?? [],
            'retry_config' => $endpoint->retry_config ?? [],
            'signature_header' => $endpoint->signature_header,
            'signature_format' => $endpoint->signature_format,
            'signature_algo' => $endpoint->signature_algo,
            'failure_count' => $endpoint->failure_count,
            'last_triggered_at' => $lastTriggeredAt?->toIso8601String(),
            'created_at' => $endpoint->created_at?->toIso8601String(),
        ]));
    }
}
