<?php

namespace App\Mcp\Tools\Webhook;

use App\Domain\Webhook\Enums\WebhookEvent;
use App\Domain\Webhook\Models\WebhookEndpoint;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class WebhookUpdateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'webhook_update';

    protected string $description = 'Update a webhook endpoint. You can change name, URL, subscribed events, active status, or headers.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'webhook_id' => $schema->string()
                ->description('The webhook endpoint UUID')
                ->required(),
            'name' => $schema->string()
                ->description('New display name'),
            'url' => $schema->string()
                ->description('New destination URL'),
            'events' => $schema->array()
                ->description('Event types to subscribe to (replaces current list)')
                ->items($schema->string()),
            'is_active' => $schema->boolean()
                ->description('Enable or disable the webhook'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['webhook_id' => 'required|string']);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }
        $endpoint = WebhookEndpoint::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['webhook_id']);

        if (! $endpoint) {
            return $this->notFoundError('webhook endpoint');
        }

        $updates = array_filter([
            'name' => $request->get('name'),
            'url' => $request->get('url'),
            'events' => $request->get('events'),
            'is_active' => $request->get('is_active'),
        ], fn ($v) => $v !== null);

        if (empty($updates)) {
            return $this->invalidArgumentError('No fields to update. Provide name, url, events, or is_active.');
        }

        // Validate event values if provided
        if (isset($updates['events'])) {
            $validEvents = array_column(WebhookEvent::cases(), 'value');
            foreach ($updates['events'] as $event) {
                if ($event !== '*' && ! in_array($event, $validEvents)) {
                    return $this->invalidArgumentError("Invalid event: {$event}. Valid events: ".implode(', ', $validEvents).', *');
                }
            }
        }

        $endpoint->update($updates);
        $endpoint->refresh();

        return Response::text(json_encode([
            'success' => true,
            'id' => $endpoint->id,
            'name' => $endpoint->name,
            'url' => $endpoint->url,
            'events' => $endpoint->events,
            'is_active' => $endpoint->is_active,
            'updated_at' => $endpoint->updated_at->toIso8601String(),
        ]));
    }
}
