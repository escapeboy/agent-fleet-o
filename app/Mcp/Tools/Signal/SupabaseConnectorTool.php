<?php

namespace App\Mcp\Tools\Signal;

use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[AssistantTool('read')]
class SupabaseConnectorTool extends Tool
{
    protected string $name = 'supabase_connector_manage';

    protected string $description = 'Manage Supabase CDC (Change Data Capture) signal connector. Get setup instructions for Database Webhooks so Supabase table changes trigger FleetQ experiments.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: get_setup_instructions | list_drivers')
                ->enum(['get_setup_instructions', 'list_drivers'])
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'action' => 'required|string|in:get_setup_instructions,list_drivers',
        ]);

        try {
            return match ($validated['action']) {
                'list_drivers' => $this->listDrivers(),
                'get_setup_instructions' => $this->getSetupInstructions(),
            };
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }

    private function listDrivers(): Response
    {
        return Response::text(json_encode([
            'drivers' => [
                [
                    'driver' => 'supabase_webhook',
                    'name' => 'Supabase Database Webhook',
                    'type' => 'push_webhook',
                    'description' => 'Receives CDC events (INSERT/UPDATE/DELETE) from a Supabase project via Database Webhooks (pg_net).',
                    'webhook_url' => url('/api/signals/webhook'),
                    'signature_header' => 'X-Webhook-Secret',
                    'signature_format' => 'plain secret (not HMAC)',
                    'supports_tables' => 'all tables with Database Webhooks enabled',
                    'requires_replica_identity' => 'FULL replica identity required for old_record on UPDATE/DELETE',
                ],
            ],
        ]));
    }

    private function getSetupInstructions(): Response
    {
        $webhookUrl = url('/api/signals/webhook');

        return Response::text(json_encode([
            'webhook_url' => $webhookUrl,
            'steps' => [
                '1. Go to your Supabase dashboard → Database → Webhooks → Create a new webhook',
                '2. Set the webhook URL to: '.$webhookUrl,
                '3. Add a custom HTTP header: X-Webhook-Secret = <generate a random secret>',
                '4. Add a second header: X-Connector-Driver = supabase_webhook',
                '5. Select the table(s) and events (INSERT, UPDATE, DELETE) you want to monitor',
                '6. (Optional) For UPDATE/DELETE events to include the old row data, run in your Supabase SQL editor:',
                '   ALTER TABLE your_table REPLICA IDENTITY FULL;',
                '7. Save the webhook — Supabase will now push events to FleetQ in real time',
                '8. In FleetQ, create a Trigger Rule with source_type = supabase_cdc and configure conditions on the table/event fields',
            ],
            'payload_format' => [
                'type' => 'INSERT | UPDATE | DELETE',
                'schema' => 'public',
                'table' => 'your_table_name',
                'record' => ['id' => '...', '...other columns...'],
                'old_record' => ['id' => '...', '...previous values...'],
            ],
            'trigger_rule_example' => [
                'condition' => 'payload.event_type == "INSERT" AND payload.table == "orders"',
                'tags_filter' => ['supabase', 'insert', 'orders'],
            ],
            'important_notes' => [
                'Supabase Database Webhooks deliver at-most-once (no automatic retries on failure).',
                'The pg_net extension must be enabled in your Supabase project (enabled by default on all plans).',
                'The webhook secret is sent as a plain header value, not HMAC — keep it secret.',
            ],
        ]));
    }
}
