<?php

namespace App\Mcp\Methods;

use App\Mcp\Exceptions\InputRequiredException;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\ToolInvoker;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * laravel/mcp 1.0's ToolInvoker is Errable: it turns every exception a tool
 * throws into an error Response. InputRequiredException is a control-flow
 * signal for SEP-2322 multi round-trip, not an error, so it is let through to
 * MultiRoundTripCallTool. Everything else keeps upstream handling.
 */
class MrtrToolInvoker extends ToolInvoker
{
    protected function callHandler(callable $handler, JsonRpcRequest $request): mixed
    {
        $inputRequired = null;

        $result = parent::callHandler(function () use ($handler, &$inputRequired): mixed {
            try {
                return $handler();
            } catch (InputRequiredException $e) {
                $inputRequired = $e;

                return null;
            }
        }, $request);

        if ($inputRequired !== null) {
            throw $inputRequired;
        }

        return $result;
    }

    /**
     * The terminal response a pre-MRTR client gets, serialized exactly as a
     * normal tool result.
     */
    public function terminal(Tool $tool, JsonRpcRequest $request, Response $response): JsonRpcResponse
    {
        return $this->toJsonRpcResponse($request, $response, $this->serializable($tool));
    }
}
