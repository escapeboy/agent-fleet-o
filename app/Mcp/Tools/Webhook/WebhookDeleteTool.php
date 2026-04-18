<?php

namespace App\Mcp\Tools\Webhook;

use App\Domain\Webhook\Models\WebhookEndpoint;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('destructive')]
class WebhookDeleteTool extends Tool
{
    protected string $name = 'webhook_delete';

    protected string $description = 'Delete a webhook endpoint. This is permanent and cannot be undone.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'webhook_id' => $schema->string()
                ->description('The webhook endpoint UUID to delete')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['webhook_id' => 'required|string']);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }
        $endpoint = WebhookEndpoint::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['webhook_id']);

        if (! $endpoint) {
            return Response::error('Webhook endpoint not found.');
        }

        $name = $endpoint->name;
        $endpoint->delete();

        return Response::text(json_encode([
            'success' => true,
            'message' => "Webhook \"{$name}\" deleted.",
        ]));
    }
}
