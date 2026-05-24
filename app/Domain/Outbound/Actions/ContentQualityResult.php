<?php

declare(strict_types=1);

namespace App\Domain\Outbound\Actions;

/**
 * Outcome of a pre-send content quality gate.
 */
final readonly class ContentQualityResult
{
    /**
     * @param  list<string>  $brandViolations  deterministic brand-voice violations
     * @param  list<string>  $qualityIssues  LLM-judge issues (empty when llm_check off)
     */
    public function __construct(
        public bool $passed,
        public ?float $score = null,
        public array $brandViolations = [],
        public array $qualityIssues = [],
    ) {}

    /**
     * @return list<string>
     */
    public function reasons(): array
    {
        return array_merge($this->brandViolations, $this->qualityIssues);
    }
}
