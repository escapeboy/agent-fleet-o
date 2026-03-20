<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Webhook\WebhookCreateTool;
use App\Mcp\Tools\Webhook\WebhookDeleteTool;
use App\Mcp\Tools\Webhook\WebhookListTool;
use App\Mcp\Tools\Webhook\WebhookUpdateTool;

class WebhookManageTool extends CompactTool
{
    protected string $name = 'webhook_manage';

    protected string $description = 'Manage outbound webhook endpoints. Actions: list, create (url, events, secret), update (webhook_id + fields), delete (webhook_id).';

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
