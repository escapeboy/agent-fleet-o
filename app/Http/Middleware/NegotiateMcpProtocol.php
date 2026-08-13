<?php

namespace App\Http\Middleware;

use App\Mcp\Protocol\ProtocolContext;
use App\Mcp\Protocol\ProtocolVersions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MCP spec 2026-07-28 dual-format negotiation for the Streamable HTTP endpoints.
 *
 * Runs before the JSON-RPC handler and resolves which revision the request is
 * speaking, so a single endpoint serves both formats during the transition:
 *
 * 1. SEP-2575 — negotiation data moved out of `initialize` into `params._meta`
 *    (protocolVersion / clientInfo / clientCapabilities). Parsed here and bound
 *    as a ProtocolContext for downstream method handlers.
 * 2. SEP-2567 — protocol-level sessions removed. For 2026-07-28 requests the
 *    inbound Mcp-Session-Id is dropped so nothing downstream can make the
 *    request session-affine, which is what lets fleetq.net serve /mcp from any
 *    node behind the load balancer.
 * 3. SEP-2243 — MCP-Protocol-Version / Mcp-Method / Mcp-Name request headers.
 *    A header that contradicts the body is rejected with 400 rather than
 *    silently trusting one side.
 *
 * Legacy clients (2025-11-25 and older) that send `initialize` and a session id
 * are untouched: no `_meta`, no SEP-2243 headers, nothing to contradict.
 */
class NegotiateMcpProtocol
{
    public function handle(Request $request, Closure $next): Response
    {
        $body = $this->decodeBody($request);
        $meta = $this->metaFrom($body);

        $metaVersion = $this->stringOrNull($meta[ProtocolVersions::META_PROTOCOL_VERSION] ?? null);
        $headerVersion = $this->stringOrNull($request->header(ProtocolVersions::HEADER_PROTOCOL_VERSION));

        if ($metaVersion !== null && $headerVersion !== null && $metaVersion !== $headerVersion) {
            return $this->mismatch(
                $body,
                'Header ['.ProtocolVersions::HEADER_PROTOCOL_VERSION."] is [{$headerVersion}] but params._meta declares [{$metaVersion}].",
            );
        }

        $declared = $metaVersion ?? $headerVersion;

        if ($declared !== null && ! ProtocolVersions::supports($declared)) {
            return $this->error(
                $body,
                -32600,
                "Unsupported MCP protocol version: {$declared}. Supported: ".implode(', ', ProtocolVersions::SUPPORTED),
            );
        }

        if (($violation = $this->validateRoutingHeaders($request, $body)) !== null) {
            return $violation;
        }

        // An `initialize` body version only *proposes* a revision — the Initialize
        // handler answers unsupported proposals with a JSON-RPC error carrying the
        // supported list, which is how old clients downgrade. Never 400 it here.
        $version = $declared
            ?? $this->supportedInitializeVersion($body)
            ?? ProtocolVersions::FALLBACK;

        $stateless = ProtocolVersions::isStateless($version);

        if ($stateless) {
            $request->headers->remove(ProtocolVersions::HEADER_SESSION_ID);
        }

        app()->instance(ProtocolContext::BINDING, new ProtocolContext(
            version: $version,
            stateless: $stateless,
            clientInfo: $this->arrayOrNull($meta[ProtocolVersions::META_CLIENT_INFO] ?? null),
            clientCapabilities: $this->arrayOrNull($meta[ProtocolVersions::META_CLIENT_CAPABILITIES] ?? null),
        ));

        return $next($request);
    }

    /**
     * SEP-2243 — reject a request whose routing headers contradict its body.
     *
     * Absent headers are allowed: they are advisory for intermediaries, and
     * requiring them would break every client that has not adopted 2026-07-28.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function validateRoutingHeaders(Request $request, ?array $body): ?Response
    {
        $bodyMethod = $this->stringOrNull($body['method'] ?? null);
        $methodHeader = $this->stringOrNull($request->header(ProtocolVersions::HEADER_METHOD));

        if ($methodHeader !== null && $bodyMethod !== null && $methodHeader !== $bodyMethod) {
            return $this->mismatch(
                $body,
                'Header ['.ProtocolVersions::HEADER_METHOD."] is [{$methodHeader}] but the body method is [{$bodyMethod}].",
            );
        }

        $nameHeader = $this->stringOrNull($request->header(ProtocolVersions::HEADER_NAME));

        if ($nameHeader === null) {
            return null;
        }

        $bodyName = $this->targetName($body);

        if ($bodyName === null || $nameHeader !== $bodyName) {
            return $this->mismatch(
                $body,
                'Header ['.ProtocolVersions::HEADER_NAME."] is [{$nameHeader}] but the body targets ["
                    .($bodyName ?? 'nothing').'].',
            );
        }

        return null;
    }

    /**
     * The primitive a request addresses: tool/prompt name, or resource URI.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function targetName(?array $body): ?string
    {
        $params = $body['params'] ?? null;

        if (! is_array($params)) {
            return null;
        }

        return match ($body['method'] ?? null) {
            'tools/call', 'prompts/get' => $this->stringOrNull($params['name'] ?? null),
            'resources/read' => $this->stringOrNull($params['uri'] ?? null),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function supportedInitializeVersion(?array $body): ?string
    {
        if (($body['method'] ?? null) !== 'initialize') {
            return null;
        }

        $proposed = $this->stringOrNull($body['params']['protocolVersion'] ?? null);

        return ProtocolVersions::supports($proposed) ? $proposed : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeBody(Request $request): ?array
    {
        $content = $request->getContent();

        if ($content === '') {
            return null;
        }

        $decoded = json_decode($content, true);

        // Batches (a JSON array) carry no single method to validate against.
        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function metaFrom(?array $body): array
    {
        $meta = $body['params']['_meta'] ?? null;

        return is_array($meta) ? $meta : [];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function arrayOrNull(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function mismatch(?array $body, string $message): Response
    {
        return $this->error($body, -32600, 'MCP request header/body mismatch. '.$message);
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function error(?array $body, int $code, string $message): Response
    {
        $id = $body['id'] ?? null;

        return response()->json([
            'jsonrpc' => '2.0',
            ...(is_string($id) || is_int($id) ? ['id' => $id] : []),
            'error' => ['code' => $code, 'message' => $message],
        ], 400);
    }
}
