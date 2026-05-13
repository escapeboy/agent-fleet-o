<?php

namespace App\Domain\Crew\Enums;

enum CrewExecutionTrustMode: string
{
    case FullConsensus = 'full_consensus';
    case MajorityConsensus = 'majority_consensus';
    case SingleAgent = 'single_agent';
    case LlmJudge = 'llm_judge';

    public function label(): string
    {
        return match ($this) {
            self::FullConsensus => 'Full Consensus',
            self::MajorityConsensus => 'Majority Consensus',
            self::SingleAgent => 'Single Agent',
            self::LlmJudge => 'LLM Judge',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::FullConsensus => 'green',
            self::MajorityConsensus => 'yellow',
            self::SingleAgent => 'blue',
            self::LlmJudge => 'purple',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::FullConsensus => 'All agents agreed on first pass — highest trust',
            self::MajorityConsensus => 'Most agents agreed after iteration',
            self::SingleAgent => 'Single coordinator agent executed without workers',
            self::LlmJudge => 'Adversarial debate resolved by synthesis',
        };
    }
}
