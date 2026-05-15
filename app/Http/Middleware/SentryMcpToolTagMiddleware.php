<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\Response;

class SentryMcpToolTagMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST')) {
            $body = $request->json()->all();

            if (
                isset($body['method'], $body['params']['name'])
                && $body['method'] === 'tools/call'
            ) {
                $toolName = $body['params']['name'];

                \Sentry\configureScope(function (Scope $scope) use ($toolName): void {
                    $scope->setTag('mcp.tool', $toolName);
                    $scope->setContext('mcp', ['tool' => $toolName]);
                });
            }
        }

        return $next($request);
    }
}
