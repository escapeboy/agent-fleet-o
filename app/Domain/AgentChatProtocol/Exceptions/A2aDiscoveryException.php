<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Exceptions;

use RuntimeException;

/**
 * Thrown when A2A agent-card discovery cannot complete: the feature flag is off,
 * the well-known card could not be fetched, or the returned document is not a
 * valid A2A AgentCard.
 */
class A2aDiscoveryException extends RuntimeException {}
