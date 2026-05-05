<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Exceptions;

use RuntimeException;

final class RemoteAgentTimeoutException extends RuntimeException {}
