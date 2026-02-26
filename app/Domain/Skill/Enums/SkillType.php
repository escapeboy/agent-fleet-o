<?php

namespace App\Domain\Skill\Enums;

enum SkillType: string
{
    case Llm = 'llm';
    case Connector = 'connector';
    case Rule = 'rule';
    case Hybrid = 'hybrid';
    case Guardrail = 'guardrail';
    case MultiModelConsensus = 'multi_model_consensus';
    case CodeExecution = 'code_execution';
    case Browser = 'browser';
    case RunpodEndpoint = 'runpod_endpoint';

    public function label(): string
    {
        return match ($this) {
            self::Llm => 'LLM-Backed',
            self::Connector => 'Connector',
            self::Rule => 'Rule-Based',
            self::Hybrid => 'Hybrid',
            self::Guardrail => 'Guardrail',
            self::MultiModelConsensus => 'Multi-Model Consensus',
            self::CodeExecution => 'Code Execution',
            self::Browser => 'Browser Automation',
            self::RunpodEndpoint => 'RunPod Endpoint',
        };
    }
}
