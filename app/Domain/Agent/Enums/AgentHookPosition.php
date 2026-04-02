<?php

namespace App\Domain\Agent\Enums;

enum AgentHookPosition: string
{
    case PreExecute = 'pre_execute';
    case PostExecute = 'post_execute';
    case PreReasoning = 'pre_reasoning';
    case PostReasoning = 'post_reasoning';
    case OnToolCall = 'on_tool_call';
    case OnError = 'on_error';

    public function label(): string
    {
        return match ($this) {
            self::PreExecute => 'Before Execution',
            self::PostExecute => 'After Execution',
            self::PreReasoning => 'Before Reasoning',
            self::PostReasoning => 'After Reasoning',
            self::OnToolCall => 'On Tool Call',
            self::OnError => 'On Error',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PreExecute => 'Runs before the agent starts processing. Can modify input context or cancel execution.',
            self::PostExecute => 'Runs after execution completes. Can transform the output before it is returned.',
            self::PreReasoning => 'Runs before each LLM reasoning step. Can inject additional context.',
            self::PostReasoning => 'Runs after each LLM reasoning step. Can filter or transform the response.',
            self::OnToolCall => 'Runs when a tool is invoked. Can intercept, modify, or block the call.',
            self::OnError => 'Runs when an error occurs. Can recover, retry, or log.',
        };
    }
}
