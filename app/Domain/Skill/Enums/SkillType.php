<?php

namespace App\Domain\Skill\Enums;

enum SkillType: string
{
    case Llm = 'llm';
    case Connector = 'connector';
    case Rule = 'rule';
    case Hybrid = 'hybrid';
    case Guardrail = 'guardrail';

    public function label(): string
    {
        return match ($this) {
            self::Llm => 'LLM-Backed',
            self::Connector => 'Connector',
            self::Rule => 'Rule-Based',
            self::Hybrid => 'Hybrid',
            self::Guardrail => 'Guardrail',
        };
    }
}
