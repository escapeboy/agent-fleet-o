<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Integration\IntegrationCapabilitiesTool;
use App\Mcp\Tools\Integration\IntegrationConnectTool;
use App\Mcp\Tools\Integration\IntegrationDisconnectTool;
use App\Mcp\Tools\Integration\IntegrationExecuteTool;
use App\Mcp\Tools\Integration\IntegrationListTool;
use App\Mcp\Tools\Integration\IntegrationPingTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class IntegrationManageTool extends CompactTool
{
    protected string $name = 'integration_manage';

    protected string $description = <<<'TXT'
Third-party service integrations (Airtable, Notion, Linear, Stripe, Slack, GitHub via OAuth2 + driver interface). Each integration declares typed `capabilities` (action endpoints) discoverable at runtime. `execute` invokes one capability with params validated against the driver's schema; output is normalized to JSON.

Actions:
- list (read) — optional: driver, status filter.
- connect (write) — driver, name, credentials (object — driver-specific). Initiates OAuth or stores API keys.
- disconnect (DESTRUCTIVE) — integration_id. Revokes tokens and deletes the connection.
- ping (read) — integration_id. Health-check the upstream API.
- execute (write — side effects on upstream) — integration_id, integration_action (capability name), params (object).
- capabilities (read) — integration_id. Available actions + parameter schemas.
TXT;

    protected function toolMap(): array
    {
        return [
            'list' => IntegrationListTool::class,
            'connect' => IntegrationConnectTool::class,
            'disconnect' => IntegrationDisconnectTool::class,
            'ping' => IntegrationPingTool::class,
            'execute' => IntegrationExecuteTool::class,
            'capabilities' => IntegrationCapabilitiesTool::class,
        ];
    }
}
