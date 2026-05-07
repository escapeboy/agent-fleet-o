<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Webhook\WebhookCreateTool;
use App\Mcp\Tools\Webhook\WebhookDeleteTool;
use App\Mcp\Tools\Webhook\WebhookListTool;
use App\Mcp\Tools\Webhook\WebhookUpdateTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class WebhookManageTool extends CompactTool
{
    protected string $name = 'webhook_manage';

    protected string $description = <<<'TXT'
Outbound webhook endpoints — URLs the platform POSTs to when subscribed events fire (experiment.completed, signal.ingested, approval.decided, etc.). Each delivery is HMAC-signed with the configured `secret` (header: `X-FleetQ-Signature`). Failed deliveries retry with exponential backoff up to 24h.

Actions:
- list (read) — all webhooks for the team.
- get (read) — webhook_id. Includes recent delivery stats.
- create (write) — url, events[] (array of event names), secret (used for HMAC signing; show once).
- update (write) — webhook_id + any creatable field. Updating `secret` invalidates the old one immediately.
- delete (DESTRUCTIVE) — webhook_id. In-flight deliveries are cancelled.
TXT;

    protected function toolMap(): array
    {
        return [
            'list' => WebhookListTool::class,
            'create' => WebhookCreateTool::class,
            'update' => WebhookUpdateTool::class,
            'delete' => WebhookDeleteTool::class,
        ];
    }
}
