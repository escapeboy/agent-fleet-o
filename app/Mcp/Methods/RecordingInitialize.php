<?php

namespace App\Mcp\Methods;

use App\Mcp\Services\McpAppsCapability;
use Laravel\Mcp\Server\Methods\Initialize;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * Upstream initialize plus recording the client's declared capabilities, which
 * replaces the SessionInitialized event laravel/mcp 1.0 removed.
 */
class RecordingInitialize extends Initialize
{
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $capabilities = $request->params['capabilities'] ?? null;
        McpAppsCapability::recordInitialize(is_array($capabilities) ? $capabilities : null);

        return parent::handle($request, $context);
    }
}
