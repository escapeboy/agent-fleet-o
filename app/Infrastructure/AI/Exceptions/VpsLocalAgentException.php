<?php

namespace App\Infrastructure\AI\Exceptions;

use RuntimeException;

class VpsLocalAgentException extends RuntimeException
{
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
        return new self("Claude Code VPS concurrency cap reached ({$cap} concurrent calls per team).");
    }
}
