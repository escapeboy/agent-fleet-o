<?php

namespace App\Domain\Agent\Enums;

enum AgentHookType: string
{
    case PromptInjection = 'prompt_injection';
    case OutputTransform = 'output_transform';
    case Guardrail = 'guardrail';
    case Notification = 'notification';
    case ContextEnrichment = 'context_enrichment';

    public function label(): string
    {
        return match ($this) {
            self::PromptInjection => 'Prompt Injection',
            self::OutputTransform => 'Output Transform',
            self::Guardrail => 'Guardrail',
            self::Notification => 'Notification',
            self::ContextEnrichment => 'Context Enrichment',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PromptInjection => 'Injects additional text into the system or user prompt',
            self::OutputTransform => 'Transforms the agent output (e.g., translate, format, redact)',
            self::Guardrail => 'Validates input/output against rules; can block execution',
            self::Notification => 'Sends a notification on the given event (webhook, email, Slack)',
            self::ContextEnrichment => 'Retrieves and injects external context (KB, API, memory)',
        };
    }
}
