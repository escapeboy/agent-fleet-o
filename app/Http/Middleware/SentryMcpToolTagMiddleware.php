<?php

namespace App\Http\Middleware;

use App\Mcp\Protocol\ProtocolVersions;
use Closure;
use Illuminate\Http\Request;
use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\Response;

class SentryMcpToolTagMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST')) {
            $toolName = $this->toolName($request);

            if ($toolName !== null) {
                \Sentry\configureScope(function (Scope $scope) use ($toolName): void {
                    $scope->setTag('mcp.tool', $toolName);
                    $scope->setContext('mcp', ['tool' => $toolName]);
                });
            }
        }

        return $next($request);
    }

    /**
     * Prefer the SEP-2243 routing headers — they name the method and target
     * without decoding the body, and NegotiateMcpProtocol has already rejected
     * the request if they contradict it. Fall back to the body for clients
     * older than 2026-07-28, which send no such headers.
     */
    private function toolName(Request $request): ?string
    {
        $method = $request->header(ProtocolVersions::HEADER_METHOD);
        $name = $request->header(ProtocolVersions::HEADER_NAME);

        if ($method === 'tools/call' && is_string($name) && $name !== '') {
            return $name;
        }

        $body = $request->json()->all();

        if (($body['method'] ?? null) === 'tools/call' && isset($body['params']['name'])) {
            return $body['params']['name'];
        }

        return null;
    }
}
