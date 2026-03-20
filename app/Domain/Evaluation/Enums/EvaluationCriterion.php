<?php

namespace App\Domain\Evaluation\Enums;

enum EvaluationCriterion: string
{
    case Faithfulness = 'faithfulness';
    case Relevance = 'relevance';
    case Correctness = 'correctness';
    case Completeness = 'completeness';

    public function label(): string
    {
        return match ($this) {
            self::Faithfulness => 'Faithfulness',
            self::Relevance => 'Relevance',
            self::Correctness => 'Correctness',
            self::Completeness => 'Completeness',
        };
    }
}
