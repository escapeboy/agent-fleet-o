<?php

namespace App\Domain\Orchestration\Enums;

/**
 * The orchestration shape recommended for a goal. Recommendation only — the
 * platform never auto-executes based on this.
 */
enum OrchestrationTier: string
{
    case SingleAgent = 'single_agent';
    case Crew = 'crew';
    case Workflow = 'workflow';

    public function label(): string
    {
        return match ($this) {
            self::SingleAgent => 'Single Agent',
            self::Crew => 'Crew',
            self::Workflow => 'Workflow',
        };
    }
}
