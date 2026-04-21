<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Mcp\Exceptions\DeadlineExceededException;
use Illuminate\Support\Facades\Log;

/**
 * Request-scoped holder for the current MCP call's deadline.
 *
 * Bound as a singleton so nested tool calls within the same request
 * inherit the parent deadline automatically.
 */
class DeadlineContext
{
    private const MIN_DEADLINE_MS = 100;

    private ?float $expiresAtMicroSeconds = null;

    private ?int $originalDeadlineMs = null;

    public function set(int $deadlineMs): void
    {
        if ($deadlineMs < self::MIN_DEADLINE_MS) {
            Log::warning('DeadlineContext: deadline_ms below minimum, clamping', [
                'requested_ms' => $deadlineMs,
                'clamped_ms' => self::MIN_DEADLINE_MS,
            ]);
            $deadlineMs = self::MIN_DEADLINE_MS;
        }

        $this->expiresAtMicroSeconds = microtime(true) + ($deadlineMs / 1000);
        $this->originalDeadlineMs = $deadlineMs;
    }

    public function clear(): void
    {
        $this->expiresAtMicroSeconds = null;
        $this->originalDeadlineMs = null;
    }

    public function isSet(): bool
    {
        return $this->expiresAtMicroSeconds !== null;
    }

    public function remaining(): ?int
    {
        if ($this->expiresAtMicroSeconds === null) {
            return null;
        }

        $remainingMs = (int) floor(($this->expiresAtMicroSeconds - microtime(true)) * 1000);

        return max(0, $remainingMs);
    }

    public function expired(): bool
    {
        if ($this->expiresAtMicroSeconds === null) {
            return false;
        }

        return microtime(true) >= $this->expiresAtMicroSeconds;
    }

    /**
     * @throws DeadlineExceededException
     */
    public function assertNotExpired(): void
    {
        if (! $this->isSet()) {
            return;
        }

        if ($this->expired()) {
            throw DeadlineExceededException::afterMs(
                $this->originalDeadlineMs ?? 0,
                $this->originalDeadlineMs ?? 0,
            );
        }
    }
}
