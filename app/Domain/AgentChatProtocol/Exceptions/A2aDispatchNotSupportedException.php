<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Exceptions;

use RuntimeException;

/**
 * Thrown when something tries to *dispatch a message* to an A2A external agent.
 *
 * A2A discovery (reading the AgentCard) is implemented; A2A message dispatch
 * (JSON-RPC `message/send` + task lifecycle) is a deliberately deferred slice.
 * This exception is the boundary marker — it prevents A2A agents from silently
 * falling through to the generic HTTP `POST {endpoint}/chat` path, which is not
 * the A2A wire protocol.
 */
class A2aDispatchNotSupportedException extends RuntimeException {}
