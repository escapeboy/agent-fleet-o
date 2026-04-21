<?php

declare(strict_types=1);

namespace App\Mcp\Exceptions;

use RuntimeException;

class DeadlineExceededException extends RuntimeException
{
    public static function afterMs(int $elapsedMs, int $deadlineMs): self
    {
        return new self(
            sprintf('Deadline exceeded: %d ms elapsed, %d ms budget.', $elapsedMs, $deadlineMs),
        );
    }
}
