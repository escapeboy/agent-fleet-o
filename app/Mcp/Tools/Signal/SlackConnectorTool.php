<?php

namespace App\Mcp\Tools\Signal;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class SlackConnectorTool extends Tool
{
    protected string $name = 'slack_connector_manage';

    protected string $description = 'Manage the Slack signal connector. Get webhook setup instructions for receiving Slack messages, mentions, and reactions as signals.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: get_setup_instructions | status')
                ->enum(['get_setup_instructions', 'status'])
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $action = $request->get('action', 'get_setup_instructions');

        return match ($action) {
            'status' => $this->status(),
            default => $this->getSetupInstructions(),
        };
    }

    private function status(): Response
    {
        $secret = config('services.slack.signing_secret');

        return Response::text(json_encode([
            'configured' => (bool) $secret,
            'webhook_url' => url('/api/signals/slack'),
            'signing_secret_set' => (bool) $secret,
            'supported_events' => ['message.channels', 'message.groups', 'app_mention', 'reaction_added'],
        ]));
    }

    private function getSetupInstructions(): Response
    {
        return Response::text(json_encode([
            'webhook_url' => url('/api/signals/slack'),
            'driver' => 'slack',
            'type' => 'push_webhook',
            'signature_header' => 'X-Slack-Signature',
            'signature_format' => 'v0=<hex> HMAC-SHA256',
            'env_var' => 'SLACK_SIGNING_SECRET',
            'services_config' => "Add to config/services.php:\n'slack' => ['signing_secret' => env('SLACK_SIGNING_SECRET')]",
            'steps' => [
                '1. Go to api.slack.com/apps → Create New App (or use existing)',
                '2. Under "Event Subscriptions", enable Events and set Request URL to: '.url('/api/signals/slack'),
                '3. Under "Subscribe to bot events", add: message.channels, message.groups, app_mention, reaction_added',
                '4. Copy the "Signing Secret" from "Basic Information" and set SLACK_SIGNING_SECRET in your .env',
                '5. Under "OAuth & Permissions", add scopes: channels:history, groups:history, im:history, channels:read',
                '6. Install the app to your workspace',
                '7. Invite the bot to the channels you want to monitor',
            ],
            'note' => 'Slack requires a 200 response within 3 seconds. Heavy processing is handled asynchronously.',
            'url_verification' => 'The endpoint automatically handles the url_verification challenge.',
        ]));
    }
}
