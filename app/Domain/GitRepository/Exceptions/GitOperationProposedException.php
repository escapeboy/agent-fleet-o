<?php

namespace App\Domain\GitRepository\Exceptions;

use RuntimeException;

/**
 * Thrown by GitOperationGate when team policy requires human review for
 * the requested git method. The caller (MCP tool / API controller) must
 * catch and surface a "proposed" response carrying the proposal id.
 */
class GitOperationProposedException extends RuntimeException
{
    public function __construct(
        public readonly string $proposalId,
        public readonly string $method,
        public readonly string $riskLevel,
    ) {
        parent::__construct("Git operation '{$method}' proposed for human review (proposal_id={$proposalId}, risk={$riskLevel}).");
    }
}
