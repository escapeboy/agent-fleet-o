<?php

namespace App\Mcp\Tools\Webhook;

use App\Domain\Webhook\Models\WebhookEndpoint;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
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

        $endpoint = WebhookEndpoint::find($validated['webhook_id']);

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
