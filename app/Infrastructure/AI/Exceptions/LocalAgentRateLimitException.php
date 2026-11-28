<?php

namespace App\Infrastructure\AI\Exceptions;

use Carbon\CarbonImmutable;

/**
 * A local agent (Claude Code) stopped because its usage limit was reached.
 * Carries the reset time and limit type when Claude Code reported them.
 */
class LocalAgentRateLimitException extends LocalAgentErrorResultException
{
    public function __construct(
        string $agentName,
        string $subtype,
        string $detail,
        public readonly ?CarbonImmutable $resetsAt = null,
        public readonly ?string $limitType = null,
    ) {
        $resets = $resetsAt !== null
            ? 'resets at '.$resetsAt->utc()->format('Y-m-d\TH:i:s\Z')
            : 'reset time unknown';

        parent::__construct(
            $agentName,
            $subtype,
            $detail,
            "Local agent '{$agentName}' hit its usage limit (".($limitType ?? 'unknown')."); {$resets}",
        );
    }

    /**
     * Seconds until the usage limit resets (never negative), or null when the
     * reset time is unknown.
     */
    public function secondsUntilReset(): ?int
    {
        if ($this->resetsAt === null) {
            return null;
        }

        return max(0, $this->resetsAt->getTimestamp() - CarbonImmutable::now()->getTimestamp());
    }
}
