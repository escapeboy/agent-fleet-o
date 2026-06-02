<?php

namespace App\Domain\Approval\DTOs;

/**
 * The outcome of evaluating a policy against a ProposalContext. A policy may
 * only narrow autonomy: decisions are AllowAuto (safe to auto-execute),
 * RequireHuman (hold for approval), or Deny (refuse outright). The reason is
 * human-readable and recorded for the explain/replay surface.
 */
class PolicyVerdict
{
    public const ALLOW_AUTO = 'allow_auto';

    public const REQUIRE_HUMAN = 'require_human';

    public const DENY = 'deny';

    /**
     * @param  array<string, mixed>  $caps  snapshot of cap checks for the explain card
     */
    public function __construct(
        public readonly string $decision,
        public readonly string $reason,
        public readonly string $effectiveRisk,
        public readonly array $caps = [],
    ) {}

    public static function allowAuto(string $reason, string $effectiveRisk, array $caps = []): self
    {
        return new self(self::ALLOW_AUTO, $reason, $effectiveRisk, $caps);
    }

    public static function requireHuman(string $reason, string $effectiveRisk, array $caps = []): self
    {
        return new self(self::REQUIRE_HUMAN, $reason, $effectiveRisk, $caps);
    }

    public static function deny(string $reason, string $effectiveRisk, array $caps = []): self
    {
        return new self(self::DENY, $reason, $effectiveRisk, $caps);
    }

    public function isAllowAuto(): bool
    {
        return $this->decision === self::ALLOW_AUTO;
    }

    public function isDeny(): bool
    {
        return $this->decision === self::DENY;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'decision' => $this->decision,
            'reason' => $this->reason,
            'effective_risk' => $this->effectiveRisk,
            'caps' => $this->caps,
        ];
    }
}
