<?php

namespace App\Mcp\Tools\System;

use App\Http\Controllers\WellKnownFleetQController;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class SystemDiscoveryGetTool extends Tool
{
    protected string $name = 'system_discovery_get';

    protected string $description = 'Return the public capability manifest also served at GET /.well-known/fleetq — name, version, MCP endpoints, REST API base URL, auth scheme, and MCP tool count. Useful for introspecting which fields the operator has chosen to expose.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $payload = app(WellKnownFleetQController::class)->buildPayload();

        return Response::text(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
