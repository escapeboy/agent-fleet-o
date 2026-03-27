<?php

namespace App\Domain\Crew\Enums;

enum CrewProcessType: string
{
    case Sequential = 'sequential';
    case Parallel = 'parallel';
    case Hierarchical = 'hierarchical';
    case SelfClaim = 'self_claim';
    case Adversarial = 'adversarial';

    public function label(): string
    {
        return match ($this) {
            self::Sequential => 'Sequential',
            self::Parallel => 'Parallel',
            self::Hierarchical => 'Hierarchical',
            self::SelfClaim => 'Self-Claim Pool',
            self::Adversarial => 'Adversarial Debate',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Sequential => 'Tasks execute one after another, each receiving the previous output',
            self::Parallel => 'Independent tasks execute concurrently, results gathered at the end',
            self::Hierarchical => 'Coordinator dynamically decides what to do next at each iteration',
            self::SelfClaim => 'Agents autonomously pull tasks from a shared pool — maximises utilisation when task durations vary',
            self::Adversarial => 'Agents are assigned competing hypotheses and actively challenge each other — ideal for debugging and root cause analysis',
        };
    }
}
