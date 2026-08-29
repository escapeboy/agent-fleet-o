<?php

namespace App\Mcp\Methods;

use App\Mcp\Exceptions\InputRequiredException;
use App\Mcp\Protocol\ProtocolContext;
use Generator;
use Illuminate\Container\Container;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Laravel\Mcp\Server\Transport\JsonRpcResponse;

/**
 * `tools/call` with SEP-2322 multi round-trip support.
 *
 * A gated tool that cannot finish throws InputRequiredException; this converts
 * it into the non-terminal result the spec defines:
 *
 *   {"resultType":"input_required","inputRequests":{…},"requestState":"…"}
 *
 * Before that, the retry parameters the client echoed back
 * (`params.inputResponses`, `params.requestState`) are bound into the container
 * so the tool can read them without re-parsing the JSON-RPC envelope — the same
 * shape `Server` uses for `mcp.request`.
 *
 * Backward compatibility is handled here rather than in the tools: pre-2026-07-28
 * callers get the terminal Response the exception carries, so `resultType` is
 * only ever added on the input_required path. The spec makes that safe —
 * "if this field is not provided, the Client should assume a ResultType of
 * 'complete' for backwards compatibility" — so successful results stay byte-identical
 * for every client.
 */
class MultiRoundTripCallTool extends CallTool
{
    public const BINDING_INPUT_RESPONSES = 'mcp.input_responses';

    public const BINDING_REQUEST_STATE = 'mcp.request_state';

    public const RESULT_TYPE_INPUT_REQUIRED = 'input_required';

    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        $container = Container::getInstance();

        $inputResponses = $request->params['inputResponses'] ?? [];
        $requestState = $request->params['requestState'] ?? null;

        $container->instance(self::BINDING_INPUT_RESPONSES, is_array($inputResponses) ? $inputResponses : []);
        $container->instance(self::BINDING_REQUEST_STATE, is_string($requestState) ? $requestState : null);

        try {
            return parent::handle($request, $context);
        } catch (InputRequiredException $e) {
            return $this->inputRequiredResponse($request, $context, $e);
        } finally {
            $container->forgetInstance(self::BINDING_INPUT_RESPONSES);
            $container->forgetInstance(self::BINDING_REQUEST_STATE);
        }
    }

    private function inputRequiredResponse(JsonRpcRequest $request, ServerContext $context, InputRequiredException $e): JsonRpcResponse
    {
        if (! ProtocolContext::current()->supportsMultiRoundTrip()) {
            return $this->terminalFallback($request, $context, $e);
        }

        $result = ['resultType' => self::RESULT_TYPE_INPUT_REQUIRED];

        if ($e->inputRequests !== []) {
            $result['inputRequests'] = $e->inputRequests;
        }

        if ($e->requestState !== null) {
            $result['requestState'] = $e->requestState->encode();
        }

        return JsonRpcResponse::result($request->id, $result);
    }

    /**
     * Render the tool's own terminal response for a client that cannot resume.
     *
     * Delegates to CallTool's own serializer rather than hand-building the
     * envelope, so a pre-MRTR caller gets byte-identical output to what it got
     * before this class existed — including structured content and _meta, which
     * a hand-rolled `content`/`isError` pair would silently drop the moment a
     * gated tool returned anything richer than plain text.
     */
    private function terminalFallback(JsonRpcRequest $request, ServerContext $context, InputRequiredException $e): JsonRpcResponse
    {
        $tool = $context->tools()->first(
            fn ($tool): bool => $tool->name() === ($request->params['name'] ?? null),
        );

        if ($tool === null) {
            // Unreachable in practice: parent::handle() already resolved the
            // tool to reach the throw. Kept so a lookup change upstream
            // degrades to a valid response rather than a TypeError.
            return JsonRpcResponse::result($request->id, [
                'content' => [['type' => 'text', 'text' => (string) $e->fallback->content()]],
                'isError' => $e->fallback->isError(),
            ]);
        }

        return $this->toJsonRpcResponse($request, $e->fallback, $this->serializable($tool));
    }
}
