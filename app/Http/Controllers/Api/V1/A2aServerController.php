<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\AgentChatProtocol\Services\A2aServer;
use App\Domain\AgentChatProtocol\Services\HmacJwtVerifier;
use App\Http\Controllers\Concerns\ResolvesChatProtocolAgent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A2A JSON-RPC endpoint for a single agent — the server half of the protocol
 * FleetQ already speaks as a client through A2aClient.
 *
 * Authorization is the same trait the REST chat endpoint uses, so an agent's
 * visibility governs both transports identically.
 *
 * @tags Agent Chat Protocol
 */
class A2aServerController extends Controller
{
    use ResolvesChatProtocolAgent;

    public function __construct(
        private readonly A2aServer $server,
        private readonly HmacJwtVerifier $jwt,
    ) {}

    public function __invoke(Request $request, string $agentId): JsonResponse
    {
        if (! (bool) config('agent_chat.a2a.server_enabled', false)) {
            abort(404, 'A2A server is not enabled');
        }

        $agent = $this->resolveChatProtocolAgent($request, $agentId, $this->jwt);
        $this->enforceChatProtocolRate($request, $agent);

        $body = $request->all();

        // A JSON-RPC fault is reported inside a 200 envelope, not as an HTTP
        // status — the peer parses `error`, and a bare 400 would tell it nothing
        // about which member of the call was wrong.
        return response()->json($this->server->handle($agent, $body));
    }
}
