<?php

namespace App\Domain\Integration\Exceptions;

use RuntimeException;

/**
 * Thrown by IntegrationActionGate when team policy requires human review
 * for the requested action. The caller (controller / MCP tool) must catch
 * and surface a "proposed" response carrying the proposal id.
 */
class IntegrationActionProposedException extends RuntimeException
{
    public function __construct(
        public readonly string $proposalId,
        public readonly string $action,
        public readonly string $riskLevel,
    ) {
        parent::__construct("Integration action '{$action}' proposed for human review (proposal_id={$proposalId}, risk={$riskLevel}).");
    }
}
