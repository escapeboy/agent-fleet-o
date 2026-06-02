<?php

namespace App\Domain\Approval\DTOs;

/**
 * The facts a policy is evaluated against. Built at the gate / proposal
 * creation seam from the operation being attempted.
 */
class ProposalContext
{
    /**
     * @param  list<string>  $paths  filesystem-ish paths the action touches (for sensitive-path rules)
     */
    public function __construct(
        public readonly string $targetType,
        public readonly string $riskLevel,
        public readonly ?float $estimatedCredits = null,
        public readonly array $paths = [],
        public readonly ?string $agentId = null,
        public readonly ?int $rubricTotal = null,
    ) {}
}
