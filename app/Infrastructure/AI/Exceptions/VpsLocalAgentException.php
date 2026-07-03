<?php

namespace App\Infrastructure\AI\Exceptions;

use RuntimeException;

class VpsLocalAgentException extends RuntimeException
{
    /**
     * True when the failure is a transient shared-resource limit (the per-team
     * VPS concurrency cap) rather than a defect. Callers re-dispatch after a
     * backoff instead of failing the run.
     */
    public bool $retryable = false;

    public static function notConfigured(): self
    {
        return new self('Claude Code VPS is not configured (CLAUDE_CODE_OAUTH_TOKEN missing).');
    }

    public static function notAllowed(): self
    {
        return new self('Claude Code VPS is not available for this user/team.');
    }

    public static function binaryMissing(string $path): self
    {
        return new self("Claude Code VPS binary not found at: {$path}");
    }

    public static function concurrencyCapReached(int $cap): self
    {
        $e = new self("Claude Code VPS concurrency cap reached ({$cap} concurrent calls per team).");
        $e->retryable = true;

        return $e;
    }
}
