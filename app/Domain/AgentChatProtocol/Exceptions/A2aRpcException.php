<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Exceptions;

/**
 * A JSON-RPC level failure while serving an inbound A2A call.
 *
 * Carries the A2A error code so the transport can emit a spec-shaped `error`
 * member instead of an HTTP status: A2A peers read the JSON-RPC envelope, and
 * a bare 4xx tells them nothing about *which* part of the call was wrong.
 *
 * @see https://a2a-protocol.org/latest/specification/ §8 Error Handling
 */
class A2aRpcException extends \RuntimeException
{
    public const PARSE_ERROR = -32700;

    public const INVALID_REQUEST = -32600;

    public const METHOD_NOT_FOUND = -32601;

    public const INVALID_PARAMS = -32602;

    public const INTERNAL_ERROR = -32603;

    public const TASK_NOT_FOUND = -32001;

    public const TASK_NOT_CANCELABLE = -32002;

    public function __construct(public readonly int $rpcCode, string $message)
    {
        parent::__construct($message);
    }

    public static function methodNotFound(string $method): self
    {
        return new self(self::METHOD_NOT_FOUND, "Method not found: {$method}");
    }

    public static function invalidParams(string $detail): self
    {
        return new self(self::INVALID_PARAMS, $detail);
    }

    public static function taskNotFound(string $id): self
    {
        return new self(self::TASK_NOT_FOUND, "Task not found: {$id}");
    }
}
