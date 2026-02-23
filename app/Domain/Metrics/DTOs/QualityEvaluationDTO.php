<?php

namespace App\Domain\Metrics\DTOs;

final readonly class QualityEvaluationDTO
{
    public function __construct(
        public float $overallScore,
        public array $dimensionScores,
        public string $feedback,
        public string $evaluationMethod,
        public string $judgeModel,
    ) {}
}
