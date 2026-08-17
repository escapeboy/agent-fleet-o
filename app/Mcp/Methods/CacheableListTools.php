<?php

namespace App\Mcp\Methods;

use App\Mcp\Protocol\ProtocolContext;
use Laravel\Mcp\Server\Methods\ListTools;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Laravel\Mcp\Server\Transport\JsonRpcResponse;

/**
 * `tools/list` with SEP-2549 cache hints.
 *
 * FleetQ ships 140+ tools on the compact server and 500+ on the full one, so a
 * client that re-lists on every conversation pays for the whole catalogue each
 * time. `ttlMs` tells it how long the page stays valid.
 *
 * `cacheScope` deliberately defaults to "session" rather than "global": the
 * catalogue is not identical for every caller. CompactTool::shouldRegister()
 * filters the list by the team's `settings.mcp_tools` profile, so a cache keyed
 * globally would serve one team's profile to another.
 *
 * Hints are only attached for revisions that define them — older clients get
 * the untouched response.
 */
class CacheableListTools extends ListTools
{
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $response = parent::handle($request, $context);

        if (! ProtocolContext::current()->supportsCacheHints()) {
            return $response;
        }

        $result = $response->toArray()['result'] ?? null;

        if (! is_array($result)) {
            return $response;
        }

        $result['ttlMs'] = (int) config('mcp.tools_cache.ttl_ms', 300000);
        $result['cacheScope'] = (string) config('mcp.tools_cache.scope', 'session');

        return JsonRpcResponse::result($request->id, $result);
    }
}
