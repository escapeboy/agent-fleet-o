<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Exceptions;

use RuntimeException;

/**
 * Thrown when something tries to *dispatch a message* to an A2A external agent.
 *
 * Dispatch IS implemented (A2aClient speaks JSON-RPC message/send + tasks/get);
 * this marks the case where it is switched off via `agent_chat.a2a.dispatch_enabled`.
 * It exists so a disabled A2A agent fails loudly instead of silently falling
 * through to the generic HTTP `POST {endpoint}/chat` path, which is not the A2A
 * wire protocol and would reach the peer as a malformed request.
 */
class A2aDispatchNotSupportedException extends RuntimeException {}
