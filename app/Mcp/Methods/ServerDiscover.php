<?php

namespace App\Mcp\Methods;

use App\Mcp\Protocol\ProtocolContext;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Laravel\Mcp\Server\Transport\JsonRpcResponse;

/**
 * `server/discover` — the optional 2026-07-28 replacement for `initialize`.
 *
 * SEP-2575 removed the handshake, so a client that wants the server's identity,
 * capabilities and instructions asks for them explicitly instead of receiving
 * them as a side effect of connecting. Unlike `initialize` this establishes
 * nothing: no session is created and no session id is returned.
 */
class ServerDiscover implements Method
{
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        return JsonRpcResponse::result($request->id, [
            'protocolVersion' => ProtocolContext::current()->version,
            'supportedProtocolVersions' => $context->supportedProtocolVersions,
            'capabilities' => $context->serverCapabilities,
            'serverInfo' => [
                'name' => $context->serverName,
                'version' => $context->serverVersion,
            ],
            'instructions' => $context->instructions,
        ]);
    }
}
